# Background Job Dispatcher (Box 5/14)

**Part of the PHP ISR (Incremental Static Regeneration) Toolkit**

Background job dispatcher for queuing regeneration tasks after HTTP response is sent. Clean interface-based design that integrates with any queue system.

---

## 🎯 Role in ISR Architecture

This is **Box 5** of a 14-component ISR toolkit. It handles background job dispatching for static page regeneration.

### The ISR Toolkit

```
Box 1: RequestClassifier    → Should we cache this request?
Box 2: CacheKeyGenerator     → Generate cache key
Box 3: FileCacheStore        → Store/retrieve cached content
Box 4: FreshnessCalculator   → Is cache fresh/stale/expired?
Box 5: BackgroundDispatcher  → Queue regeneration jobs (YOU ARE HERE)
Box 6-14: [TBD]              → Orchestration, regeneration, CDN, etc.
```

### How It Fits Together

```php
// ISR request flow (conceptual)

// 1. Classify request
$decision = $classifier->classify($request);
if (!$decision['cacheable']) {
    return $response; // Dynamic response
}

// 2. Generate cache key
$key = $keyGen->generate(['url' => $url]);

// 3. Check cache
$cached = $store->read($key);

// 4. Check freshness
$freshness = $calculator->calculate([
    'created_at' => $cached['created_at'],
    'ttl' => $cached['ttl']
]);

// 5. Serve cached content
if ($freshness['status'] === 'fresh') {
    return $cached['content']; // Fast path
}

// 6. Stale content? Serve while revalidating
if ($freshness['status'] === 'stale') {
    // Return stale content immediately
    $response = $cached['content'];
    
    // Queue regeneration in background (THIS IS WHERE BOX 5 COMES IN)
    $dispatcher->dispatch([
        'task' => 'regenerate_static_page',
        'params' => [
            'url' => $url,
            'cache_key' => $key,
            'priority' => 'high'
        ]
    ]);
    
    return $response;
}

// 7. Expired? Generate now
return generateStaticPage($url);
```

---

## 🏗️ What This Box Does

**Single Responsibility:** Dispatch jobs to execute after HTTP response is sent.

**NOT responsible for:**
- ❌ Deciding what to regenerate (that's the orchestrator)
- ❌ Actually regenerating pages (that's your app)
- ❌ Routing tasks to handlers (that's your app)
- ❌ ISR logic (that's boxes 1-4 + orchestrator)

**IS responsible for:**
- ✅ Queuing jobs efficiently
- ✅ Supporting multiple backends (FastCGI, custom queues)
- ✅ Clean interface for swapping queue systems

---

## 📦 Core Components

### BackgroundJobInterface (The Contract)

```php
interface BackgroundJobInterface {
    public function dispatch(string $task, array $params): string;
    public function isAvailable(): bool;
}
```

This is the only thing you need to implement to integrate with Box 5.

### BackgroundDispatcher (The Router)

Routes job dispatch requests to your handler implementation.

```php
$dispatcher = new BackgroundDispatcher($yourHandler);
$result = $dispatcher->dispatch([
    'task' => 'regenerate',
    'params' => ['url' => '/blog/post']
]);
```

### Included Handler Examples

1. **FastCGIHandler** - Base class example using `fastcgi_finish_request()`
2. **SyncHandler** - Fallback that executes immediately (for testing/non-FPM environments)

**Important:** These are reference implementations. For production ISR, implement your own handler that knows about your specific tasks.

---

## 🔧 Integration with ISR Orchestrator

### Recommended Pattern

Your ISR orchestrator should provide its own handler that routes tasks to the appropriate boxes:

```php
<?php
// In your ISR orchestrator

class ISRBackgroundHandler implements BackgroundJobInterface
{
    private ContentGenerator $generator;      // Box 6
    private InvalidationResolver $resolver;   // Box 7
    private CachePurger $purger;             // Box 8
    
    public function __construct(
        ContentGenerator $generator,
        InvalidationResolver $resolver,
        CachePurger $purger
    ) {
        $this->generator = $generator;
        $this->resolver = $resolver;
        $this->purger = $purger;
    }
    
    public function dispatch(string $task, array $params): string
    {
        $jobId = 'job_' . bin2hex(random_bytes(8));
        
        register_shutdown_function(function () use ($task, $params) {
            // Send HTTP response to client first
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // Now execute the ISR task in background
            match ($task) {
                'regenerate_page' => $this->generator->regenerate($params),
                'invalidate_related' => $this->resolver->resolve($params),
                'purge_cdn' => $this->purger->purge($params),
                default => error_log("Unknown ISR task: {$task}")
            };
        });
        
        return $jobId;
    }
    
    public function isAvailable(): bool
    {
        return function_exists('fastcgi_finish_request');
    }
}

// In your ISR orchestrator setup
class ISREngine
{
    private BackgroundDispatcher $dispatcher;
    
    public function __construct(/* boxes 6, 7, 8... */)
    {
        $handler = new ISRBackgroundHandler(
            $contentGenerator,
            $invalidationResolver,
            $cachePurger
        );
        
        $this->dispatcher = new BackgroundDispatcher($handler);
    }
    
    public function handleStaleCache(array $cached, string $url): Response
    {
        // Serve stale content
        $response = new Response($cached['content']);
        
        // Queue regeneration in background
        $this->dispatcher->dispatch([
            'task' => 'regenerate_page',
            'params' => [
                'url' => $url,
                'cache_key' => $cached['key']
            ]
        ]);
        
        return $response;
    }
}
```

### Why This Approach?

**Separation of Concerns:**
- Box 5 doesn't know what "regenerate" means
- Your orchestrator knows how to route ISR tasks
- Easy to test each component independently
- Easy to swap queue backends

---

## 🔌 Alternative: Framework Adapters

If you're using a framework with its own queue system, implement the interface:

### Laravel Queue

```php
class LaravelQueueHandler implements BackgroundJobInterface
{
    public function dispatch(string $task, array $params): string
    {
        $jobId = uniqid('laravel_');
        
        Queue::push(new ISRRegenerateJob($task, $params));
        
        return $jobId;
    }
    
    public function isAvailable(): bool
    {
        return class_exists('Illuminate\Support\Facades\Queue');
    }
}

// Usage
$dispatcher = new BackgroundDispatcher(new LaravelQueueHandler());
```

### WordPress Action Scheduler

```php
class WordPressActionSchedulerHandler implements BackgroundJobInterface
{
    public function dispatch(string $task, array $params): string
    {
        $jobId = uniqid('wp_');
        
        as_enqueue_async_action(
            'isr_background_task',
            ['task' => $task, 'params' => $params],
            'isr'
        );
        
        return $jobId;
    }
    
    public function isAvailable(): bool
    {
        return function_exists('as_enqueue_async_action');
    }
}

// Register action handler
add_action('isr_background_task', function($data) {
    $task = $data['task'];
    $params = $data['params'];
    
    // Route to your ISR boxes
    match($task) {
        'regenerate_page' => regenerate_page($params),
        'purge_cdn' => purge_cdn($params),
        default => error_log("Unknown task: $task")
    };
}, 10, 1);
```

### Redis Queue

```php
class RedisQueueHandler implements BackgroundJobInterface
{
    private Redis $redis;
    
    public function __construct(string $host = '127.0.0.1', int $port = 6379)
    {
        $this->redis = new Redis();
        $this->redis->connect($host, $port);
    }
    
    public function dispatch(string $task, array $params): string
    {
        $jobId = uniqid('redis_');
        
        $this->redis->rPush('isr:jobs', json_encode([
            'id' => $jobId,
            'task' => $task,
            'params' => $params,
            'queued_at' => time()
        ]));
        
        return $jobId;
    }
    
    public function isAvailable(): bool
    {
        return extension_loaded('redis');
    }
}
```

---

## 📊 Performance & Benchmarks

### What We Test

✅ **Dispatch Speed:** 100 jobs dispatched in ~1-2ms (dispatch overhead only)  
⚠️ **Async Execution:** Requires PHP-FPM integration testing (see below)

### Benchmark Results

```
PHPUnit Tests:
✔ Dispatch 100 jobs in <50ms (typically 1-2ms)
✔ Dispatch overhead: ~0.01-0.02ms per job
✔ All tests pass (13 tests, 38 assertions)
```

**Important Notes:**

1. **Unit tests use SyncHandler** because FastCGI isn't available in test environment
2. **Benchmarks test dispatch speed**, not async execution timing
3. **Real async behavior** requires PHP-FPM (see integration test below)

### Testing Async Behavior (Optional)

To verify jobs actually run AFTER HTTP response:

```bash
# 1. Set up PHP-FPM server (Apache/Nginx + PHP-FPM)
# 2. Access the integration test:
curl http://localhost/fastcgi-integration-test.php

# 3. Check the log:
cat /tmp/fastcgi_integration_test.log

# Expected: Job timestamps AFTER response timestamp
```

See `fastcgi-integration-test.php` for details.

---

## 🧪 Testing

### Option 1: PHPUnit (Recommended)

```bash
./phpunit.phar --testdox BackgroundDispatcherTest.php
```

**Tests:**
- ✅ Basic dispatch functionality
- ✅ Auto-detection of handlers
- ✅ Custom handler injection
- ✅ Batch dispatch
- ✅ Dispatch performance (<50ms for 100 jobs)
- ✅ Handler interface compliance
- ✅ Input validation

### Option 2: Standalone Test Runner

```bash
php test-standalone.php
```

Works without PHPUnit.

### Option 3: Integration Test (FastCGI)

```bash
# Requires PHP-FPM setup
curl http://localhost/fastcgi-integration-test.php
cat /tmp/fastcgi_integration_test.log
```

Verifies jobs execute AFTER HTTP response.

---

## 📂 Files

**Core Library:**
- `BackgroundJobInterface.php` - Handler interface (15 lines)
- `BackgroundDispatcher.php` - Main dispatcher (90 lines)
- `FastCGIHandler.php` - FastCGI base class example (85 lines)
- `SyncHandler.php` - Synchronous fallback (50 lines)

**Testing:**
- `BackgroundDispatcherTest.php` - PHPUnit tests (13 tests)
- `test-standalone.php` - Standalone test runner (no PHPUnit required)
- `fastcgi-integration-test.php` - FastCGI async verification test

**Examples:**
- `example.php` - Basic usage examples
- `isr-integration-example.php` - Full ISR flow with boxes 1-5

---

## 🎯 Design Philosophy

### Why Interface-Based?

Each ISR component is **infrastructure**:
- FileCacheStore doesn't care about ISR logic
- CacheKeyGenerator doesn't care about ISR logic
- **BackgroundDispatcher doesn't care about ISR logic**

They're **building blocks** for the ISR orchestrator to use.

### Why No Task Routing in Box 5?

```php
// ❌ BAD: Coupling to ISR logic
class BackgroundDispatcher {
    protected function executeJob($job) {
        match($job['task']) {
            'regenerate_static_page' => $this->regenerate(...),
            'purge_cdn' => $this->purgeCdn(...),
            // ... ISR-specific logic
        };
    }
}

// ✅ GOOD: Infrastructure layer
class BackgroundDispatcher {
    // Just dispatch. App handles routing.
}

// Your orchestrator:
$handler = new ISRBackgroundHandler(/* your boxes */);
$dispatcher = new BackgroundDispatcher($handler);
```

The **ISR orchestrator** handles task routing, not Box 5.

### Why FastCGIHandler Doesn't Execute Tasks

`FastCGIHandler` is a **reference implementation** showing how to use `fastcgi_finish_request()`.

For production, you should:
1. Extend it and override `executeJob()`, OR
2. Implement `BackgroundJobInterface` from scratch, OR
3. Use a framework adapter (Laravel, WordPress, Redis)

Box 5 provides the plumbing. Your orchestrator provides the logic.

---

## 🔮 Future Enhancements (Out of Scope for Box 5)

These belong in the **orchestrator** or as separate boxes:

- ❌ Job priority queues
- ❌ Retry logic with exponential backoff
- ❌ Dead letter queues
- ❌ Job status tracking
- ❌ Rate limiting
- ❌ Scheduled jobs

Box 5's job: **Dispatch efficiently. Stay simple.**

---

## ⚙️ Requirements

- PHP 8.0+ (typed properties, match expressions)
- Optional: PHP-FPM for async execution
- Optional: Your favorite queue system (Laravel, WordPress, Redis, etc.)

---

## 📄 License

MIT - Part of the PHP ISR Toolkit

---

## 🤝 Integration Summary

**Box 5 is infrastructure.** Use it like this:

```php
// 1. Implement handler in your ISR orchestrator
class ISRBackgroundHandler implements BackgroundJobInterface {
    // Route tasks to boxes 6, 7, 8...
}

// 2. Create dispatcher with your handler
$dispatcher = new BackgroundDispatcher(new ISRBackgroundHandler(...));

// 3. Dispatch jobs when cache is stale
$dispatcher->dispatch([
    'task' => 'regenerate_page',
    'params' => ['url' => $url, 'cache_key' => $key]
]);
```

**That's it.** Clean separation of concerns. 🎯
