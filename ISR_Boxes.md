---

## The Black Boxes (Complete List)

Let me organize this into concrete, AI-assignable tasks:

---

### **Box 1: Request Classifier**
**Directive:**
"Given an HTTP request, determine if it should be cached. Return a decision object with yes/no and the reason."

**Files:**
- `RequestClassifier.php` (main logic)
- `RequestClassifierTest.php` (tests)
- `README.md` (what it does, how to use)

**Input:**
```php
[
    'method' => 'GET',
    'url' => '/blog/post-123',
    'cookies' => ['wordpress_logged_in_abc' => '...'],
    'query' => ['utm_source' => 'google'],
    'headers' => [...]
]
```

**Output:**
```php
[
    'cacheable' => false,
    'reason' => 'User is logged in (cookie: wordpress_logged_in_abc)',
    'rule_triggered' => 'logged_in_user'
]
```

**Benchmark:**
- Process 10,000 requests in <100ms
- Correctly identify cacheable vs non-cacheable for test suite

---

### **Box 2: Cache Key Generator**
**Directive:**
"Convert a URL and context into a unique, safe cache key. Handle variations (mobile, language, etc)."

**Files:**
- `CacheKeyGenerator.php`
- `CacheKeyGeneratorTest.php`
- `README.md`

**Input:**
```php
[
    'url' => '/blog/post-123',
    'variants' => [
        'mobile' => true,
        'language' => 'es'
    ]
]
```

**Output:**
```php
'a3f2b8c9d1e4f5g6h7i8j9k0' // Unique hash
```

**Benchmark:**
- Generate 100,000 keys in <50ms
- Zero collisions for test dataset
- Same input always produces same key

---

### **Box 3: File Cache Store**
**Directive:**
"Store and retrieve HTML content from filesystem. Handle read, write, delete, and list operations."

**Files:**
- `FileCacheStore.php`
- `FileCacheStoreTest.php`
- `README.md`

**Input (write):**
```php
[
    'key' => 'abc123',
    'content' => '<html>...</html>',
    'ttl' => 60,
    'metadata' => ['size' => 1024, 'url' => '/page']
]
```

**Output (read):**
```php
[
    'content' => '<html>...</html>',
    'created_at' => 1234567890,
    'ttl' => 60,
    'metadata' => [...]
]
// OR null if not found
```

**Benchmark:**
- Write 1000 entries in <500ms
- Read 10,000 entries in <200ms
- Handle concurrent writes safely

---

### **Box 4: Freshness Calculator**
**Directive:**
"Given a cache entry and TTL, determine if it's fresh, stale, or expired."

**Files:**
- `FreshnessCalculator.php`
- `FreshnessCalculatorTest.php`
- `README.md`

**Input:**
```php
[
    'created_at' => 1234567890,
    'ttl' => 60,
    'current_time' => 1234567950
]
```

**Output:**
```php
[
    'status' => 'stale', // 'fresh' | 'stale' | 'expired'
    'age_seconds' => 60,
    'expires_in_seconds' => 0
]
```

**Benchmark:**
- Process 1,000,000 calculations in <100ms
- Correctly handle edge cases (TTL=0, negative times, etc)

---

### **Box 5: Background Job Dispatcher**
**Directive:**
"Queue a job to run after the HTTP response is sent. Support multiple backends (fastcgi, cron, Swoole)."

**Files:**
- `BackgroundDispatcher.php`
- `BackgroundDispatcherTest.php`
- `README.md`

**Input:**
```php
[
    'task' => 'regenerate',
    'params' => ['url' => '/blog/post-123'],
    'method' => 'fastcgi' // or 'cron' or 'swoole'
]
```

**Output:**
```php
[
    'queued' => true,
    'job_id' => 'job_abc123',
    'method_used' => 'fastcgi'
]
```

**Benchmark:**
- Dispatch 100 jobs in <10ms
- Jobs actually execute after response sent
- Fallback to sync if async not available

---

### **Box 6: Content Generator Wrapper**
**Directive:**
"Execute a callback that generates HTML, capture the output, handle errors gracefully."

**Files:**
- `ContentGenerator.php`
- `ContentGeneratorTest.php`
- `README.md`

**Input:**
```php
[
    'generator' => function() {
        echo "<html>...</html>";
    },
    'timeout' => 30,
    'url' => '/blog/post-123'
]
```

**Output:**
```php
[
    'success' => true,
    'html' => '<html>...</html>',
    'generation_time_ms' => 234,
    'error' => null
]
```

**Benchmark:**
- Capture output correctly 100% of the time
- Handle errors without crashing
- Respect timeout limits

---

### **Box 7: Invalidation Rule Resolver**
**Directive:**
"Given an event (post updated, comment added), determine which cache keys should be invalidated."

**Files:**
- `InvalidationResolver.php`
- `InvalidationResolverTest.php`
- `README.md`

**Input:**
```php
[
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'homepage' => ['latest_posts'],
        'category_page' => ['tech'],
        'author_page' => ['author_5']
    ]
]
```

**Output:**
```php
[
    'cache_keys_to_purge' => [
        'abc123', // /blog/post-123
        'def456', // homepage
        'ghi789', // /category/tech
        'jkl012'  // /author/john
    ],
    'reason' => 'Post 123 updated affects 4 pages'
]
```

**Benchmark:**
- Resolve dependencies for 1000 events in <500ms
- Correctly identify all affected pages

---

### **Box 8: Cache Purger**
**Directive:**
"Delete one or more cache entries. Support individual keys, patterns, and full purge."

**Files:**
- `CachePurger.php`
- `CachePurgerTest.php`
- `README.md`

**Input:**
```php
[
    'keys' => ['abc123', 'def456'],
    // OR
    'pattern' => '/blog/*',
    // OR
    'purge_all' => true
]
```

**Output:**
```php
[
    'purged_count' => 2,
    'keys_purged' => ['abc123', 'def456'],
    'errors' => []
]
```

**Benchmark:**
- Purge 10,000 entries in <1 second
- Pattern matching works correctly
- No cache corruption

---

### **Box 9: Response Sender**
**Directive:**
"Send HTML content to browser with appropriate headers. Handle compression, caching headers, etc."

**Files:**
- `ResponseSender.php`
- `ResponseSenderTest.php`
- `README.md`

**Input:**
```php
[
    'html' => '<html>...</html>',
    'status_code' => 200,
    'headers' => [
        'Content-Type' => 'text/html',
        'X-Cache' => 'HIT'
    ],
    'compress' => true
]
```

**Output:**
```php
// Actually sends HTTP response
[
    'sent' => true,
    'bytes_sent' => 1024,
    'compressed' => true
]
```

**Benchmark:**
- Send response in <1ms
- Compression works correctly
- Headers sent properly

---

### **Box 10: Statistics Collector**
**Directive:**
"Track metrics (cache hits, misses, generation times). Store efficiently for dashboard."

**Files:**
- `StatsCollector.php`
- `StatsCollectorTest.php`
- `README.md`

**Input:**
```php
[
    'event' => 'cache_hit',
    'url' => '/blog/post-123',
    'timestamp' => 1234567890,
    'metadata' => ['age_seconds' => 30]
]
```

**Output:**
```php
[
    'recorded' => true,
    'current_stats' => [
        'hits' => 1523,
        'misses' => 47,
        'hit_rate' => 97.0
    ]
]
```

**Benchmark:**
- Record 10,000 events in <100ms
- Minimal memory overhead
- Accurate calculations

---

### **Box 11: Configuration Manager**
**Directive:**
"Load configuration from file/env/array. Provide type-safe getters with defaults."

**Files:**
- `ConfigManager.php`
- `ConfigManagerTest.php`
- `README.md`

**Input:**
```php
[
    'cache_dir' => '/var/cache/isr',
    'ttl' => 60,
    'enable_compression' => true
]
```

**Output (methods):**
```php
$config->getCacheDir(); // '/var/cache/isr'
$config->getTTL(); // 60
$config->get('unknown', 'default'); // 'default'
```

**Benchmark:**
- Load config in <1ms
- Type safety enforced
- Validation works

---

### **Box 12: Health Monitor**
**Directive:**
"Check system health (disk space, cache directory writable, memory available). Return status."

**Files:**
- `HealthMonitor.php`
- `HealthMonitorTest.php`
- `README.md`

**Input:**
```php
[
    'cache_dir' => '/var/cache/isr',
    'checks' => ['disk_space', 'permissions', 'php_version']
]
```

**Output:**
```php
[
    'healthy' => false,
    'checks' => [
        'disk_space' => ['status' => 'ok', 'available' => '10GB'],
        'permissions' => ['status' => 'fail', 'error' => 'Not writable'],
        'php_version' => ['status' => 'ok', 'version' => '8.1.0']
    ]
]
```

**Benchmark:**
- Run all checks in <50ms
- Accurate detection

---

### **Box 13: Lock Manager**
**Directive:**
"Prevent multiple processes from regenerating the same URL simultaneously. Acquire/release locks with timeout."

**Files:**
- `LockManager.php`
- `LockManagerTest.php`
- `README.md`

**Input:**
```php
[
    'key' => 'abc123',
    'action' => 'acquire', // or 'release'
    'timeout' => 30, // seconds
]
```

**Output:**
```php
[
    'locked' => true,
    'lock_id' => 'lock_xyz789',
    'expires_at' => 1234567920,
    'already_locked' => false
]
```

**Benchmark:**
- Acquire/release 10,000 locks in <500ms
- No race conditions under concurrent load
- Stale locks auto-expire correctly

---

### **Box 14: Logger**
**Directive:**
"Log messages at different levels (debug, info, warning, error). Support multiple outputs (file, syslog, null)."

**Files:**
- `Logger.php`
- `LoggerTest.php`
- `README.md`

**Input:**
```php
[
    'level' => 'error',
    'message' => 'Cache regeneration failed',
    'context' => [
        'url' => '/blog/post-123',
        'error' => 'Timeout after 30s'
    ]
]
```

**Output:**
```php
[
    'logged' => true,
    'timestamp' => 1234567890,
    'formatted' => '[2024-01-15 10:30:00] ERROR: Cache regeneration failed | url: /blog/post-123'
]
```

**Benchmark:**
- Log 10,000 messages in <100ms
- No memory leaks on long-running processes
- Correctly filter by log level

---

## The Orchestrator (Not a Black Box)

**File:** `ISREngine.php`

**Purpose:**
Wires all the boxes together. This is the "instructions manual" that assembles the legos.

```php
// Pseudo-code of what it does
class ISREngine {
    public function handleRequest($request) {
        $decision = RequestClassifier->classify($request);

        if (!$decision['cacheable']) {
            return ContentGenerator->generate();
        }

        $key = CacheKeyGenerator->generate($request);
        $entry = FileCacheStore->get($key);

        if ($entry === null) {
            // Cache miss
            $html = ContentGenerator->generate();
            FileCacheStore->set($key, $html);
            return $html;
        }

        $freshness = FreshnessCalculator->check($entry);

        if ($freshness['status'] === 'fresh') {
            StatsCollector->record('cache_hit');
            return $entry['content'];
        }

        if ($freshness['status'] === 'stale') {
            // THE ISR MAGIC
            BackgroundDispatcher->dispatch('regenerate', ['url' => ...]);
            StatsCollector->record('stale_served');
            return $entry['content'];
        }
    }
}
```

---

## Organization Summary:

```
isr-core/
├── boxes/
│   ├── 01-request-classifier/
│   ├── 02-cache-key-generator/
│   ├── 03-file-cache-store/
│   ├── 04-freshness-calculator/
│   ├── 05-background-dispatcher/
│   ├── 06-content-generator/
│   ├── 07-invalidation-resolver/
│   ├── 08-cache-purger/
│   ├── 09-response-sender/
│   ├── 10-stats-collector/
│   ├── 11-config-manager/
│   └── 12-health-monitor/
├── orchestrator/
│   ├── ISREngine.php
│   └── ISREngineTest.php
└── assembly-guide.md
```
