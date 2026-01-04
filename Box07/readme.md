# Invalidation Rule Resolver

A simple, high-performance PHP library that determines which cache keys to invalidate when content changes. Maps events (post updated, comment added) to affected pages and their cache keys.

## What It Does

When content changes (a post is updated, a comment is added), this resolver determines exactly which cached pages are affected and provides their cache keys for purging. It handles complex dependency graphs efficiently while maintaining simplicity.

## Installation

```php
// Box07 depends on Box02 (CacheKeyGenerator)
// Make sure both folders exist:
// - Box02/CacheKeyGenerator.php
// - Box07/InvalidationResolver.php

require_once __DIR__ . '/Box07/InvalidationResolver.php';
// CacheKeyGenerator is automatically loaded from Box02

$resolver = new InvalidationResolver();
```

**Folder Structure:**
```
PHP ISR/
├── Box02/
│   ├── CacheKeyGenerator.php      ← Dependency
│   └── ...
├── Box07/
│   ├── InvalidationResolver.php   ← This box
│   ├── InvalidationResolverTest.php
│   └── readme.md
```

## Basic Usage

```php
$resolver = new InvalidationResolver();

$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'homepage' => ['latest_posts'],
        'category_page' => ['tech'],
        'author_page' => ['author_5'],
    ],
];

$result = $resolver->resolve($event);

// Result structure:
// [
//     'cache_keys_to_purge' => [
//         'abc123',  // /blog/post-123
//         'def456',  // homepage
//         'ghi789',  // /category/tech
//         'jkl012',  // /author/author_5
//     ],
//     'reason' => 'Post updated affects 4 page(s)'
// ]
```

## Input Format

```php
[
    'event' => string,          // Required: Event type (post_updated, comment_added, etc.)
    'entity_id' => mixed,       // Optional: ID of affected entity
    'entity_type' => string,    // Optional: Type of entity (post, comment, user)
    'dependencies' => [         // Optional: Related pages that need invalidation
        'homepage' => array,         // Homepage sections
        'post_page' => array,        // Post IDs (useful for comment events)
        'category_page' => array,    // Category slugs
        'author_page' => array,      // Author slugs
        'tag_pages' => array,        // Tag slugs
        'archive_page' => array,     // Archive identifiers
    ],
    'variants' => [             // Optional: Variant sets (mobile/language combinations)
        ['mobile' => bool, 'language' => string],
        // ... more variant sets
    ],
]
```

## Features

### Built-in Event Types

The resolver comes with pre-configured rules for common CMS events:

```php
// Post Events
'post_created'   → Invalidates: homepage, category, author, tags, archive, RSS
'post_updated'   → Invalidates: post page, homepage, category, author, tags
'post_deleted'   → Invalidates: post page, homepage, category, author, tags, archive

// Comment Events (⚠️ Require 'post_page' dependency)
'comment_added'   → Invalidates: post page, recent comments
'comment_updated' → Invalidates: post page
'comment_deleted' → Invalidates: post page, recent comments

// Other Events
'user_updated'     → Invalidates: author page
'category_updated' → Invalidates: category page, homepage
```

**Important:** Comment events need to specify which post they belong to via dependencies:

```php
// ❌ BAD - Won't invalidate the post page
$event = [
    'event' => 'comment_added',
    'entity_id' => 456,
    'entity_type' => 'comment',
    'dependencies' => [], // Missing post_page!
];

// ✅ GOOD - Invalidates both post page and recent comments
$event = [
    'event' => 'comment_added',
    'entity_id' => 456,
    'entity_type' => 'comment',
    'dependencies' => [
        'post_page' => [123], // The post this comment belongs to
    ],
];
```

### Dependency Resolution

The resolver automatically expands dependencies into specific URLs and cache keys:

```php
$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'homepage' => ['latest_posts', 'featured'],
        'category_page' => ['tech', 'ai', 'programming'],
        'author_page' => ['john', 'jane'],
        'tag_pages' => ['php', 'caching'],
        'post_page' => [456, 789], // Additional related posts to invalidate
    ],
];

$result = $resolver->resolve($event);

// Generates cache keys for:
// - /blog/post-123 (the primary entity)
// - /blog/post-456 (related post from dependency)
// - /blog/post-789 (related post from dependency)
// - / (homepage)
// - /?section=latest_posts
// - /?section=featured
// - /category/tech
// - /category/ai
// - /category/programming
// - /author/john
// - /author/jane
// - /tag/php
// - /tag/caching
```

### Variant Support

Handle multiple cache variants (mobile/desktop, languages) efficiently:

```php
$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'category_page' => ['tech'],
    ],
    'variants' => [
        ['mobile' => true, 'language' => 'en'],
        ['mobile' => false, 'language' => 'en'],
        ['mobile' => true, 'language' => 'es'],
        ['mobile' => false, 'language' => 'es'],
    ],
];

$result = $resolver->resolve($event);

// Generates cache keys for:
// - Post page × 4 variants = 4 keys
// - Category page × 4 variants = 4 keys
// Total: 8 cache keys to purge
```

### Batch Processing

Process multiple events efficiently:

```php
$events = [
    [
        'event' => 'post_updated',
        'entity_id' => 123,
        'entity_type' => 'post',
        'dependencies' => ['category_page' => ['tech']],
    ],
    [
        'event' => 'comment_added',
        'entity_id' => 456,
        'entity_type' => 'comment',
        'dependencies' => [],
    ],
    // ... more events
];

$results = $resolver->resolveBatch($events);

// Returns array of results with preserved indices
// Each result has 'cache_keys_to_purge' and 'reason'
```

### Custom Rules

Add your own invalidation rules:

```php
$resolver->addRule('product_updated', [
    'product_page',
    'category_page',
    'search_results',
    'homepage',
]);

// Now product_updated events will invalidate those page types
```

### Estimation

Estimate cache impact before invalidating:

```php
$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'category_page' => ['tech', 'ai'],
        'author_page' => ['john'],
    ],
];

$count = $resolver->estimateInvalidationCount($event);
// Returns: 4 (post page + 2 categories + 1 author)

// Useful for logging/monitoring cache invalidation impact
```

**Note:** Estimation is accurate and accounts for entity type. For example, comment events only count their dependencies, not the primary entity (since comments don't have their own pages).

## Use Cases

### Basic CMS Integration

```php
// When a post is published
function onPostPublished($postId, $categories, $authorSlug, $tags) {
    $resolver = new InvalidationResolver();
    
    $event = [
        'event' => 'post_created',
        'entity_id' => $postId,
        'entity_type' => 'post',
        'dependencies' => [
            'homepage' => ['latest_posts'],
            'category_page' => $categories,
            'author_page' => [$authorSlug],
            'tag_pages' => $tags,
            'archive_page' => [date('Y-m'), date('Y')],
        ],
    ];
    
    $result = $resolver->resolve($event);
    
    foreach ($result['cache_keys_to_purge'] as $key) {
        $cache->delete($key);
    }
    
    logInvalidation($result['reason']);
}

// When a comment is added/updated
function onCommentUpdated($commentId, $postId) {
    $resolver = new InvalidationResolver();
    
    $event = [
        'event' => 'comment_updated',
        'entity_id' => $commentId,
        'entity_type' => 'comment',
        'dependencies' => [
            'post_page' => [$postId], // Invalidate the post this comment belongs to
        ],
    ];
    
    $result = $resolver->resolve($event);
    purgeCache($result['cache_keys_to_purge']);
}
```

### Multi-language Sites

```php
// Clear cache for all language variants
$supportedLanguages = ['en', 'es', 'fr', 'de'];
$variants = array_map(fn($lang) => ['language' => $lang], $supportedLanguages);

$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'category_page' => ['tech'],
    ],
    'variants' => $variants,
];

$result = $resolver->resolve($event);
purgeCache($result['cache_keys_to_purge']);
```

### Mobile and Desktop Variants

```php
// Invalidate both mobile and desktop versions
$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'category_page' => ['news'],
    ],
    'variants' => [
        ['mobile' => true],
        ['mobile' => false],
    ],
];

$result = $resolver->resolve($event);
cdn_purge($result['cache_keys_to_purge']);
```

### E-commerce Product Updates

```php
// Custom rule for products
$resolver->addRule('product_updated', [
    'product_page',
    'category_page',
    'search_results',
]);

$event = [
    'event' => 'product_updated',
    'entity_id' => $productId,
    'entity_type' => 'product',
    'dependencies' => [
        'category_page' => $product->categories,
    ],
];

$result = $resolver->resolve($event);
invalidateProductCache($result['cache_keys_to_purge']);
```

### Bulk Content Operations

```php
// Process multiple events efficiently
$events = [];

foreach ($updatedPosts as $post) {
    $events[] = [
        'event' => 'post_updated',
        'entity_id' => $post->id,
        'entity_type' => 'post',
        'dependencies' => [
            'category_page' => $post->categories,
            'author_page' => [$post->author_slug],
        ],
    ];
}

$results = $resolver->resolveBatch($events);

$allKeys = [];
foreach ($results as $result) {
    $allKeys = array_merge($allKeys, $result['cache_keys_to_purge']);
}

$allKeys = array_unique($allKeys);
$cache->deleteMultiple($allKeys);
```

### Monitoring Cache Invalidations

```php
// Estimate impact before invalidating
$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'category_page' => ['tech', 'ai', 'programming'],
        'author_page' => ['john'],
        'tag_pages' => ['php', 'cache', 'performance'],
    ],
];

$estimate = $resolver->estimateInvalidationCount($event);

if ($estimate > 100) {
    logWarning("Large cache invalidation: {$estimate} keys");
}

$result = $resolver->resolve($event);
purgeCache($result['cache_keys_to_purge']);
```

## Performance

**Target:** Resolve 1,000 events in <500ms

Typical performance on modern hardware:
- **~50-100ms** for 1,000 events with simple dependencies
- **~150-200ms** for 1,000 events with complex dependency graphs
- **~2,000-20,000 events/second** depending on complexity

This is suitable for production use. Most applications generate 1-100 invalidation events per request, well within capacity.

### Running Performance Tests

```bash
phpunit InvalidationResolverTest.php --filter testPerformanceBenchmark
```

Expected output:
```
✓ Performance: 1,000 events in ~50-100ms (~10,000-20,000 events/sec)
```

### Performance Characteristics

- **Simple events** (1-2 dependencies): ~20,000 events/sec
- **Complex events** (5+ dependencies): ~5,000 events/sec
- **With variants** (4 variant sets): ~2,000 events/sec

The resolver scales linearly with:
1. Number of dependencies per event
2. Number of variant sets
3. Batch size

## Integration with CacheKeyGenerator (Box02)

The resolver uses `CacheKeyGenerator` from Box02 to produce cache keys. This ensures consistency:

```php
// Box07 automatically uses Box02's CacheKeyGenerator
// Same URL + variants = Same cache key
// Whether generated directly or via invalidation resolver

// Direct generation (Box02)
require_once __DIR__ . '/Box02/CacheKeyGenerator.php';
$generator = new CacheKeyGenerator();

// Via resolver (Box07) - uses Box02 internally
require_once __DIR__ . '/Box07/InvalidationResolver.php';
$resolver = new InvalidationResolver();

// Direct generation
$key1 = $generator->generate([
    'url' => '/blog/post-123',
    'variants' => ['mobile' => true],
]);

// Via resolver
$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [],
    'variants' => [
        ['mobile' => true],
    ],
];

$result = $resolver->resolve($event);
$key2 = $result['cache_keys_to_purge'][0];

// $key1 === $key2 (same cache key for same URL + variants)
```

## Testing

### Requirements
- PHP 7.4+
- PHPUnit 9.0+
- Box02/CacheKeyGenerator.php (dependency)

### Run All Tests

```bash
# From the Box07 directory
phpunit InvalidationResolverTest.php

# Or from the project root
phpunit Box07/InvalidationResolverTest.php
```

Expected output:
```
OK (40+ tests, 150+ assertions)
✓ Performance: 1,000 events in ~50-100ms
```

### Test Coverage

- ✅ Basic resolution (all event types)
- ✅ Cache key generation and validation
- ✅ Dependency resolution (categories, authors, tags, posts)
- ✅ Variant handling (mobile, language combinations)
- ✅ Batch processing
- ✅ Custom rules
- ✅ Estimation (including accuracy for non-post entities)
- ✅ Edge cases (no dependencies, empty events, comment events without post)
- ✅ Determinism
- ✅ Performance benchmarks

## Design Decisions

### Why Simple Rule Mapping?

The resolver uses a straightforward array-based rule system instead of complex graph traversal or database queries. This is:
- **Fast**: Array lookups are O(1)
- **Predictable**: Same input always produces same output
- **Debuggable**: Easy to understand which pages will be invalidated
- **Maintainable**: Adding new rules is trivial

### Why Generate Cache Keys (Not URLs)?

The resolver returns cache keys (MD5 hashes) rather than URLs because:
- Cache systems use keys, not URLs
- Handles variants transparently
- Ensures consistency with CacheKeyGenerator
- No ambiguity about what to purge

### Why Built-in Rules?

Pre-configured rules for common CMS events because:
- 90% of users need the same event types
- Reduces boilerplate code
- Provides sensible defaults
- Can be overridden if needed

### Why Dependency Arrays?

Dependencies are simple arrays (not objects) because:
- Easy to serialize for queues/logs
- No class autoloading required
- Simple to construct from database queries
- Works with any PHP data structure

## Limitations

### What This Is NOT

- ❌ Not a cache implementation (use Redis, Memcached, etc.)
- ❌ Not a queue system (use RabbitMQ, Beanstalkd, etc.)
- ❌ Not a publish/subscribe system
- ❌ Not a dependency tracker (doesn't store relationships)

### What This IS

- ✅ A rule-based resolver for cache invalidation
- ✅ Production-ready for web applications
- ✅ Simple to integrate and extend
- ✅ Fast and deterministic

## Common Issues

### Comment Events Not Invalidating Post Pages

**Problem:** Comment events only invalidate recent comments, not the post page.

```php
// This only invalidates /comments/recent
$event = [
    'event' => 'comment_added',
    'entity_id' => 456,
    'entity_type' => 'comment',
    'dependencies' => [], // ← Missing post_page!
];
```

**Solution:** Add the `post_page` dependency:

```php
// This invalidates both the post AND recent comments
$event = [
    'event' => 'comment_added',
    'entity_id' => 456,
    'entity_type' => 'comment',
    'dependencies' => [
        'post_page' => [$comment->post_id], // ← Add this!
    ],
];
```

**Why?** The resolver can't know which post a comment belongs to without being told. In your application, you know `$comment->post_id`, so pass it as a dependency.

### Keys Don't Match Expected URLs

The resolver generates cache keys, not URLs. To debug:

```php
// See what URLs are being generated
$event = ['event' => 'post_updated', 'entity_id' => 123, 'entity_type' => 'post'];
$result = $resolver->resolve($event);

// To see which URLs these keys represent, you'd need to
// maintain a reverse mapping or log during key generation
```

### Too Many Keys Being Invalidated

Check your dependency graph:

```php
// Use estimation to see impact
$estimate = $resolver->estimateInvalidationCount($event);
echo "Will invalidate {$estimate} keys";

// Consider if all dependencies are necessary
// Maybe some pages don't need immediate invalidation
```

### Not Enough Keys Being Invalidated

Verify your rules include all affected page types:

```php
// Check which page types are affected
$types = $resolver->getAffectedPageTypes('post_updated');
print_r($types);

// Add missing page types if needed
$resolver->addRule('post_updated', [
    'post_page',
    'homepage',
    'category_page',
    'author_page',
    'search_results',  // ← Add this if needed
]);
```

### Performance Issues

If resolution is slow:

1. **Batch your events**: Use `resolveBatch()` instead of multiple `resolve()` calls
2. **Simplify dependencies**: Do you really need 50 tag pages invalidated?
3. **Reduce variants**: Do you need all 10 language combinations?

```php
// Instead of this (slow):
foreach ($events as $event) {
    $result = $resolver->resolve($event);
    processResult($result);
}

// Do this (fast):
$results = $resolver->resolveBatch($events);
foreach ($results as $result) {
    processResult($result);
}
```

## Advanced Usage

### Conditional Invalidation

```php
// Only invalidate if significant change
if ($post->isPublishedChange() || $post->isMajorEdit()) {
    $event = [
        'event' => 'post_updated',
        'entity_id' => $post->id,
        'entity_type' => 'post',
        'dependencies' => [
            'homepage' => ['latest_posts'],
            'category_page' => $post->categories,
        ],
    ];
    
    $result = $resolver->resolve($event);
    purgeCache($result['cache_keys_to_purge']);
} else {
    // Minor edit, just invalidate the post page
    $key = $generator->generate([
        'url' => "/blog/post-{$post->id}",
        'variants' => [],
    ]);
    purgeCache([$key]);
}
```

### Layered Invalidation

```php
// Immediate: Critical pages
$criticalEvent = [
    'event' => 'post_updated',
    'entity_id' => $postId,
    'entity_type' => 'post',
    'dependencies' => [
        'homepage' => ['latest_posts'],
    ],
];

$critical = $resolver->resolve($criticalEvent);
purgeCache($critical['cache_keys_to_purge']);

// Delayed: Less critical pages (queue for later)
$secondaryEvent = [
    'event' => 'post_updated',
    'entity_id' => $postId,
    'entity_type' => 'post',
    'dependencies' => [
        'tag_pages' => $post->tags,
        'archive_page' => [$archiveDate],
    ],
];

$secondary = $resolver->resolve($secondaryEvent);
queueForInvalidation($secondary['cache_keys_to_purge'], $delay = 60);
```

### Logging and Monitoring

```php
$event = [
    'event' => 'post_updated',
    'entity_id' => 123,
    'entity_type' => 'post',
    'dependencies' => [
        'category_page' => ['tech'],
    ],
];

$result = $resolver->resolve($event);

// Log for debugging
logInfo([
    'event_type' => $event['event'],
    'entity' => "{$event['entity_type']}:{$event['entity_id']}",
    'keys_invalidated' => count($result['cache_keys_to_purge']),
    'reason' => $result['reason'],
    'cache_keys' => $result['cache_keys_to_purge'],
]);

// Monitor cache hit rates
trackMetric('cache.invalidations', count($result['cache_keys_to_purge']));
```

## Requirements

- PHP 7.4 or higher
- **Box02/CacheKeyGenerator.php** (required dependency)
- No other external dependencies

**Dependencies:**
```
Box07 (InvalidationResolver)
  ↓ depends on
Box02 (CacheKeyGenerator)
```

## Code Quality

This implementation prioritizes:
- **Simplicity**: Easy to understand rule-based system
- **Performance**: Fast enough for production use
- **Maintainability**: Clear code with good documentation
- **Extensibility**: Easy to add custom rules

The code intentionally avoids:
- Over-engineering (complex graph databases for simple mappings)
- Premature optimization (caching rule lookups, etc.)
- Feature bloat (built-in queue integration, etc.)

## Contributing

This is intentionally kept simple. If you need additional features:

1. **For custom event types:** Use `addRule()` method
2. **For custom page mapping:** Extend the class and override `generateAffectedUrls()`
3. **For integration:** Wrap the resolver in your own service layer

The code is designed to be easy to fork and modify for your specific needs.

## License

Use freely in your projects. Modify as needed.

## Support

- Check the test suite for usage examples
- Review the code comments for implementation details
- Performance issues? Run the benchmark tests

## Changelog

### Version 1.0.0
- Simple rule-based invalidation system
- Support for all common CMS events
- Dependency resolution (categories, authors, tags)
- Variant support (mobile, language)
- Batch processing
- Custom rule support
- Estimation tools
- Comprehensive test suite
- Performance: <500ms for 1,000 events
- Deterministic cache key generation
