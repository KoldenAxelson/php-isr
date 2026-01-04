# ISR (Incremental Static Regeneration) for PHP

A production-ready, modular ISR implementation for PHP that brings Next.js-style ISR to any PHP application.

## 🎯 What is ISR?

**Incremental Static Regeneration (ISR)** is a caching strategy that provides the best of both worlds:
- **Speed**: Serve cached content instantly (even when stale)
- **Freshness**: Content updates automatically in the background
- **Reliability**: No cache stampedes or concurrent regeneration

### The ISR Pattern

```
Request → Check Cache → Determine Freshness → Respond

FRESH (0-1 hour):     Serve immediately ✅
STALE (1-2 hours):    Serve + Regenerate in background 🔄
EXPIRED (>2 hours):   Generate synchronously ⏳
```

## 🏗️ Architecture

This library follows a **"Black Box Atomized Lego"** design philosophy:
- Each component is standalone and independent
- Components communicate through clean interfaces
- The Orchestrator coordinates everything

### Component Diagram

```
┌──────────────────────────────────────────────────────────┐
│                    ISROrchestrator                        │
│                  (Main Coordinator)                       │
└──────────────────────┬───────────────────────────────────┘
                       │
       ┌───────────────┼───────────────┐
       │               │               │
       ▼               ▼               ▼
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│ Request      │ │  Cache       │ │  Freshness   │
│ Classifier   │ │  Key Gen     │ │  Calculator  │
└──────────────┘ └──────────────┘ └──────────────┘
       │               │               │
       └───────────────┼───────────────┘
                       │
       ┌───────────────┼───────────────┐
       │               │               │
       ▼               ▼               ▼
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│ File Cache   │ │  Content     │ │  Response    │
│ Store        │ │  Generator   │ │  Sender      │
└──────────────┘ └──────────────┘ └──────────────┘
       │               │               │
       └───────────────┼───────────────┘
                       │
       ┌───────────────┼───────────────┐
       │               │               │
       ▼               ▼               ▼
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│ Background   │ │  Lock        │ │  Stats       │
│ Dispatcher   │ │  Manager     │ │  Collector   │
└──────────────┘ └──────────────┘ └──────────────┘
```

## 📦 Components Overview

### Core Flow Components
1. **RequestClassifier** - Determines if requests should be cached
2. **CacheKeyGenerator** - Creates deterministic cache keys
3. **FileCacheStore** - Stores and retrieves cached content
4. **FreshnessCalculator** - Determines if content is fresh/stale/expired
5. **ContentGenerator** - Executes content generation callbacks
6. **ResponseSender** - Sends HTTP responses with headers

### Background Processing
7. **BackgroundDispatcher** - Queues regeneration jobs (Box 5)
8. **ISRBackgroundHandler** - ISR-specific task router (part of orchestrator)
9. **CallbackRegistry** ⚠️ **NEEDS IMPLEMENTATION**
10. **FastCGIHandler** - Reference implementation for FastCGI
11. **SyncHandler** - Fallback synchronous execution

### Cache Management
12. **InvalidationResolver** - Maps events to cache keys
13. **CachePurger** - Deletes cache entries
14. **LockManager** - Prevents concurrent regeneration

### Utilities
15. **ConfigManager** - Type-safe configuration
16. **HealthMonitor** - System health checks
17. **StatsCollector** - Performance metrics
18. **Logger** - Logging with multiple handlers

### The Orchestrator
19. **ISROrchestrator** - Coordinates all components

## 🚀 Quick Start

### Basic Usage

```php
<?php
require_once 'ISROrchestrator.php';

// Initialize
$orchestrator = new ISROrchestrator();

// Handle request
$orchestrator->handleRequest('/page', function() {
    return "<html><body>Your content here</body></html>";
});
```

That's it! The orchestrator handles everything automatically.

### With Configuration

```php
$config = new ConfigManager([
    'cache' => [
        'dir' => '/var/www/cache/isr',
        'default_ttl' => 3600,  // 1 hour
        'use_sharding' => true,
    ],
    'freshness' => [
        'stale_window_seconds' => 1800,  // 30 min stale window
    ],
]);

$orchestrator = new ISROrchestrator($config);
```

### With Callback Registry (Recommended)

```php
// Register callbacks
$registry = new CallbackRegistry();
$registry->register('homepage', function() {
    return "<html>Homepage</html>";
});

$registry->register('blog_post', function($params) {
    $postId = $params['post_id'];
    return renderPost($postId);
});

// Initialize orchestrator
$orchestrator = new ISROrchestrator($config, $registry);

// Use registered callbacks
$orchestrator->handleRequest('/blog/post-123', null, [
    'callback_name' => 'blog_post',
    'callback_params' => ['post_id' => 123],
    'ttl' => 7200,  // 2 hours
]);
```

## 📋 Missing Components (Need Implementation)

### 1. CallbackRegistry
**Purpose**: Registry for content generation callbacks

**Spec**: See `CallbackRegistry_Spec.md`

**Key Methods**:
```php
class CallbackRegistry {
    public function register(string $name, callable $callback): void;
    public function get(string $name): ?callable;
    public function has(string $name): bool;
}
```

**Why Needed**: Solves closure serialization problem for background jobs

**Integration**: The orchestrator creates `ISRBackgroundHandler` which uses the registry to retrieve callbacks by name during background regeneration.

---

## ✅ Background Job Handling (Already Implemented!)

Based on Box 5's design philosophy, background job execution is now **integrated into the orchestrator** via `ISRBackgroundHandler` class. This is cleaner than having a separate BackgroundJobExecutor component.

**How it works**:
1. `ISRBackgroundHandler` implements `BackgroundJobInterface` (from Box 5)
2. It's created inside the orchestrator with access to all necessary components
3. When jobs are dispatched, they queue up and execute after the HTTP response
4. The handler routes tasks to appropriate components (ContentGenerator, etc.)

This follows Box 5's recommended pattern of keeping the dispatcher as infrastructure while the orchestrator provides ISR-specific routing logic.

## 🔧 Configuration

### Via PHP Array
```php
$config = new ConfigManager([
    'cache' => [
        'dir' => '/var/www/cache/isr',
        'default_ttl' => 3600,
        'use_sharding' => false,
    ],
    'freshness' => [
        'stale_window_seconds' => null,  // null = use TTL
    ],
    'background' => [
        'timeout' => 30,
        'max_retries' => 3,
    ],
    'stats' => [
        'enabled' => true,
    ],
    'compression' => [
        'enabled' => true,
        'level' => 6,
    ],
]);
```

### Via JSON File
```php
$config = ConfigManager::fromJson('config.json');
```

### Via Environment Variables
```php
$config = ConfigManager::fromEnv('ISR_');
```

Example env vars:
```bash
ISR_CACHE_DIR=/var/www/cache/isr
ISR_CACHE_DEFAULT_TTL=3600
ISR_CACHE_USE_SHARDING=true
ISR_FRESHNESS_STALE_WINDOW_SECONDS=1800
```

## 🎯 Use Cases

### WordPress Integration
```php
// Register content generators
$registry->register('single_post', function($params) {
    $postId = $params['post_id'];
    global $post;
    $post = get_post($postId);
    setup_postdata($post);
    
    ob_start();
    get_header();
    get_template_part('content', 'single');
    get_footer();
    wp_reset_postdata();
    
    return ob_get_clean();
});

// Hook into WordPress
add_action('template_redirect', function() use ($orchestrator) {
    if (is_single()) {
        global $post;
        $orchestrator->handleRequest(get_permalink(), null, [
            'callback_name' => 'single_post',
            'callback_params' => ['post_id' => $post->ID],
        ]);
        exit();
    }
});

// Invalidate on post update
add_action('save_post', function($postId) use ($orchestrator) {
    $orchestrator->invalidate([
        'event' => 'post_updated',
        'entity_type' => 'post',
        'entity_id' => $postId,
        'dependencies' => [
            'category_page' => wp_get_post_categories($postId, ['fields' => 'slugs']),
            'author_page' => [get_the_author_meta('login')],
        ],
    ]);
});
```

### Laravel Integration
```php
// In middleware
class ISRMiddleware {
    public function handle($request, Closure $next) {
        if ($request->method() !== 'GET') {
            return $next($request);
        }
        
        $callbackName = $this->getCallbackForRoute($request->route());
        if (!$callbackName) {
            return $next($request);
        }
        
        $this->orchestrator->handleRequest(
            $request->fullUrl(),
            null,
            [
                'callback_name' => $callbackName,
                'callback_params' => $request->route()->parameters(),
            ]
        );
        
        exit();
    }
}
```

### Custom PHP Application
```php
$orchestrator = new ISROrchestrator();

// Homepage
$orchestrator->handleRequest('/', function() {
    $db = new Database();
    $posts = $db->getLatestPosts(10);
    return renderTemplate('homepage', ['posts' => $posts]);
});

// Blog post
$orchestrator->handleRequest('/blog/' . $slug, function() use ($slug) {
    $db = new Database();
    $post = $db->getPostBySlug($slug);
    return renderTemplate('post', ['post' => $post]);
});
```

## 🔄 Cache Invalidation

### Event-Based Invalidation
```php
// When post is updated
$orchestrator->invalidate([
    'event' => 'post_updated',
    'entity_type' => 'post',
    'entity_id' => 123,
    'dependencies' => [
        'category_page' => ['technology', 'programming'],
        'author_page' => ['john-doe'],
        'tag_pages' => ['php', 'web-development'],
    ],
]);
```

### Pattern-Based Purging
```php
require_once 'CachePurger.php';
$purger = new CachePurger('/var/www/cache/isr');

// Purge all blog pages
$purger->purge(['pattern' => '/blog/*']);

// Purge specific keys
$purger->purge(['keys' => ['key1', 'key2', 'key3']]);

// Purge everything
$purger->purge(['purge_all' => true]);
```

### Supported Events
- `post_created` - New content created
- `post_updated` - Content modified
- `post_deleted` - Content removed
- `comment_added` - New comment
- `comment_updated` - Comment modified
- `comment_deleted` - Comment removed
- `user_updated` - User profile changed
- `category_updated` - Category changed

## 📊 Monitoring

### Get Statistics
```php
$stats = $orchestrator->getStats();
echo "Hit rate: {$stats['hit_rate']}%\n";
echo "Total requests: {$stats['total_requests']}\n";
echo "Stale serves: {$stats['stale_serves']}\n";
echo "Avg generation: {$stats['generation']['avg_time']}s\n";
```

### Health Checks
```php
$health = $orchestrator->getHealth();
if ($health['healthy']) {
    echo "System is healthy!\n";
} else {
    foreach ($health['checks'] as $check => $result) {
        if ($result['status'] !== 'ok') {
            echo "{$check}: {$result['error']}\n";
        }
    }
}
```

### Cleanup (Cron Job)
```php
// Run periodically to remove expired locks and cache
$cleanup = $orchestrator->cleanup();
echo "Removed {$cleanup['locks_removed']} locks\n";
echo "Removed {$cleanup['cache_entries_removed']} cache entries\n";
```

## 🎨 Cache Variants

Support multiple versions of the same URL:

```php
$orchestrator->handleRequest('/page', null, [
    'callback_name' => 'page',
    'variants' => [
        'lang' => 'en',           // Language
        'device' => 'mobile',      // Device type
        'theme' => 'dark',         // Theme
        'user_type' => 'premium',  // User segment
    ],
]);
```

This creates separate cache entries for each variant combination.

## ⚡ Performance

### Benchmarks (Expected)
- Fresh cache hit: **< 5ms**
- Stale serve: **< 10ms** (includes job queuing)
- Cache miss (first request): **Depends on content generation**
- Subsequent requests: **< 5ms** (cached)

### Optimization Tips
1. **Use sharding** for > 10,000 cached pages
2. **Enable compression** for large HTML pages
3. **Set appropriate TTL** - longer for static content
4. **Use variants** sparingly - they multiply cache entries
5. **Cleanup regularly** - remove expired entries via cron

## 🔒 Security

### Path Traversal Protection
- ConfigManager validates file paths
- CachePurger sanitizes cache keys
- LockManager uses MD5 for filenames

### Sensitive Data
- Use environment variables for secrets
- ConfigManager warns about hardcoded credentials
- Never cache authenticated pages (RequestClassifier handles this)

### Concurrency
- LockManager prevents race conditions
- Atomic file operations in FileCacheStore
- Safe across multiple PHP-FPM workers

## 🧪 Testing

### Unit Tests (Recommended Structure)
```
tests/
  ├── RequestClassifierTest.php
  ├── CacheKeyGeneratorTest.php
  ├── FileCacheStoreTest.php
  ├── FreshnessCalculatorTest.php
  ├── ISROrchestratorTest.php
  └── ...
```

### Integration Tests
Test full ISR flow with real components:
```php
public function testISRFlow() {
    $orchestrator = new ISROrchestrator();
    
    // First request (miss)
    $result1 = $orchestrator->handleRequest('/page', function() {
        return '<html>Content</html>';
    });
    $this->assertEquals('miss', $result1['cache_status']);
    
    // Second request (fresh)
    $result2 = $orchestrator->handleRequest('/page', function() {
        return '<html>Content</html>';
    });
    $this->assertEquals('fresh', $result2['cache_status']);
}
```

## 📚 Documentation

- `ISROrchestrator.php` - Main orchestrator implementation
- `ISR_Usage_Examples.php` - 10+ usage examples
- `BackgroundJobExecutor_Spec.md` - Spec for missing component
- `CallbackRegistry_Spec.md` - Spec for missing component
- `isr_architecture.md` - Architecture diagram

## 🤝 Contributing

This library uses a modular "Black Box" architecture. Each component:
- Has clear inputs/outputs
- Is testable in isolation
- Follows PHP strict types
- Includes comprehensive documentation

To add a new component:
1. Create standalone class with single responsibility
2. Add comprehensive docblocks
3. Write unit tests
4. Update orchestrator integration (if needed)

## 📝 License

[Your License Here]

## 🙏 Credits

Inspired by Next.js ISR pattern, adapted for PHP environments.

---

## Next Steps

1. **Implement CallbackRegistry** (see spec) - Only missing component!
2. **Write comprehensive tests** for the orchestrator
3. **Add framework-specific adapters** (WordPress plugin, Laravel package)
4. **Create monitoring dashboard** (UI for stats/health)
5. **Add Redis cache adapter** (alternative to file-based)

## Support

For questions, issues, or contributions, please [open an issue].

---

**Built with ❤️ for the PHP community**
