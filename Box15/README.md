# CallbackRegistry - PHP Background Job Callback System

A robust solution for registering and managing content generation callbacks in PHP background jobs, solving the closure serialization problem.

## The Problem

PHP cannot serialize closures/anonymous functions. This creates a challenge when dispatching background jobs that need to execute callbacks:

```php
// ❌ This doesn't work - closures can't be serialized!
$dispatcher->dispatch([
    'task' => 'regenerate',
    'callback' => function() { return "<html>...</html>"; }
]);
```

## The Solution

CallbackRegistry provides a string-based callback reference system:

```php
// ✅ This works - string references are serializable!
$registry->register('homepage', function() { return "<html>...</html>"; });

$dispatcher->dispatch([
    'task' => 'regenerate',
    'callback_name' => 'homepage'  // String reference!
]);

// In the worker process:
$callback = $registry->get('homepage');
$html = $callback();
```

## Features

- ✅ **String-based callback references** - Solve the serialization problem
- ✅ **Parameterized callbacks** - Pass runtime parameters to callbacks
- ✅ **Metadata support** - Store TTL hints, descriptions, tags, etc.
- ✅ **Thread-safe reads** - O(1) lookup performance
- ✅ **Type-safe** - Full PHP type hints and return types
- ✅ **Well-tested** - Comprehensive test suite included
- ✅ **Framework-agnostic** - Works with WordPress, Laravel, or vanilla PHP

## Installation

Simply include the `CallbackRegistry.php` file in your project:

```php
require_once 'path/to/CallbackRegistry.php';
```

Or use Composer (if you publish to Packagist):

```bash
composer require your-vendor/callback-registry
```

## Quick Start

### Basic Usage

```php
$registry = new CallbackRegistry();

// Register a callback
$registry->register('homepage', function() {
    return "<html><body>Welcome!</body></html>";
});

// Retrieve and execute
$callback = $registry->get('homepage');
$html = $callback();

echo $html;
```

### With Metadata

```php
$registry->register('blog_post', function($params) {
    return generatePost($params['post_id']);
}, [
    'description' => 'Blog post generator',
    'default_ttl' => 7200,
    'tags' => ['content', 'blog']
]);
```

### Background Job Integration

```php
// Main process - queue the job
$registry->register('homepage', function() {
    return renderHomepage();
});

$dispatcher->dispatch([
    'callback_name' => 'homepage'
]);

// Worker process - execute the job
$callback = $registry->get('homepage');
if ($callback) {
    $html = $callback();
    cache($html);
}
```

## API Reference

### Constructor

```php
public function __construct()
```

Creates a new empty registry.

### register()

```php
public function register(string $name, callable $callback, array $metadata = []): void
```

Register a content generation callback.

**Parameters:**
- `$name` - Unique name for the callback (alphanumeric, underscores, hyphens, dots)
- `$callback` - The callable function
- `$metadata` - Optional metadata (description, TTL, tags, etc.)

**Throws:**
- `InvalidArgumentException` if name is already registered or invalid

**Example:**
```php
$registry->register('product_page', function($params) {
    return renderProduct($params['id']);
}, [
    'description' => 'Product detail page',
    'default_ttl' => 3600
]);
```

### get()

```php
public function get(string $name): ?callable
```

Get a registered callback by name.

**Returns:** The callback or `null` if not found

**Example:**
```php
$callback = $registry->get('homepage');
if ($callback !== null) {
    $html = $callback();
}
```

### has()

```php
public function has(string $name): bool
```

Check if a callback is registered.

**Example:**
```php
if ($registry->has('homepage')) {
    // Callback exists
}
```

### list()

```php
public function list(): array
```

Get all registered callback names.

**Returns:** Array of callback names

**Example:**
```php
foreach ($registry->list() as $name) {
    echo "Registered: $name\n";
}
```

### getMetadata()

```php
public function getMetadata(string $name): ?array
```

Get metadata for a callback.

**Returns:** Metadata array or `null` if not found

**Example:**
```php
$meta = $registry->getMetadata('homepage');
$ttl = $meta['default_ttl'] ?? 3600;
```

### unregister()

```php
public function unregister(string $name): bool
```

Remove a registered callback.

**Returns:** `true` if removed, `false` if not found

**Example:**
```php
$registry->unregister('old_callback');
```

### Additional Methods

- `count()` - Get number of registered callbacks
- `clear()` - Remove all callbacks (useful for testing)
- `getAllInfo()` - Get detailed info about all callbacks
- `getRegisteredAt($name)` - Get registration timestamp

## Usage Patterns

### Parameterized Callbacks

For callbacks that need runtime parameters:

```php
$registry->register('blog_post', function($params) {
    $postId = $params['post_id'];
    return generatePost($postId);
});

// Later, in the worker:
$callback = $registry->get('blog_post');
$html = $callback(['post_id' => 123]);
```

### WordPress Integration

```php
function initialize_isr_callbacks($registry) {
    // Homepage
    $registry->register('homepage', function() {
        ob_start();
        get_header();
        get_template_part('homepage');
        get_footer();
        return ob_get_clean();
    });
    
    // Single post
    $registry->register('single_post', function($params) {
        global $post;
        $post = get_post($params['post_id']);
        setup_postdata($post);
        
        ob_start();
        get_template_part('content', 'single');
        wp_reset_postdata();
        return ob_get_clean();
    });
}

// Hook into post updates
add_action('save_post', function($postId) use ($dispatcher) {
    $dispatcher->dispatch([
        'callback_name' => 'single_post',
        'params' => ['post_id' => $postId]
    ]);
});
```

### Laravel Integration

```php
// In a service provider
class ISRServiceProvider extends ServiceProvider
{
    public function boot(CallbackRegistry $registry)
    {
        $registry->register('homepage', function() {
            return view('pages.home')->render();
        });
        
        $registry->register('blog.post', function($params) {
            $post = Post::findOrFail($params['id']);
            return view('blog.show', ['post' => $post])->render();
        });
    }
}

// In a job
class RegenerateCache
{
    public function handle(CallbackRegistry $registry)
    {
        $callback = $registry->get($this->callbackName);
        $html = $callback($this->params);
        Cache::put($this->cacheKey, $html, $this->ttl);
    }
}
```

## Testing

Run the comprehensive test suite:

```bash
./vendor/bin/phpunit CallbackRegistryTest.php
```

Or run the examples:

```bash
php example_background_jobs.php
php example_wordpress.php
php example_laravel.php
```

## Performance

- **get()**: O(1) - Direct array lookup
- **has()**: O(1) - Array key existence check
- **list()**: O(n) - Array keys extraction
- **Memory**: ~100 bytes per callback (minimal overhead)

## Thread Safety

- ✅ Read operations (`get`, `has`, `list`) are thread-safe
- ⚠️ Write operations (`register`, `unregister`) should happen during initialization
- ❌ Not designed for runtime registration in multi-threaded environments

## Best Practices

### 1. Initialize Once Per Process

Both your main process and worker processes must initialize the registry:

```php
// bootstrap.php (loaded in both main and worker)
function initializeRegistry() {
    $registry = new CallbackRegistry();
    
    $registry->register('homepage', ...);
    $registry->register('blog_post', ...);
    // ... register all callbacks
    
    return $registry;
}
```

### 2. Use Descriptive Names

```php
// ✅ Good
$registry->register('blog_post', ...);
$registry->register('product_detail_page', ...);
$registry->register('api.users.index', ...);

// ❌ Bad
$registry->register('cb1', ...);
$registry->register('temp', ...);
```

### 3. Add Metadata

Help future developers understand your callbacks:

```php
$registry->register('homepage', $callback, [
    'description' => 'Main homepage with featured content',
    'default_ttl' => 3600,
    'tags' => ['public', 'high-traffic'],
    'priority' => 'high',
    'updated_by' => 'john@example.com'
]);
```

### 4. Handle Missing Callbacks Gracefully

```php
$callback = $registry->get('some_callback');

if ($callback === null) {
    // Log the error
    error_log("Callback not found: some_callback");
    
    // Use fallback strategy
    return $this->generateFallbackContent();
}

$html = $callback($params);
```

### 5. Validate Callback Names

The registry automatically validates names, but be consistent:

```php
// ✅ Valid patterns
'homepage'
'blog_post'
'api.products.index'
'category-archive'
'PageTemplate123'

// ❌ Invalid (will throw exception)
'my callback'  // spaces
'blog/post'    // slashes
'email@domain' // special chars
```

## Security Considerations

1. **Validate callback names** - Already enforced by the registry
2. **Whitelist callbacks in production** - Only register known callbacks
3. **Audit trail** - Use metadata to track who registered what
4. **Input validation** - Validate parameters passed to callbacks
5. **Access control** - Restrict who can trigger background jobs

## Common Use Cases

### Incremental Static Regeneration (ISR)

Perfect for ISR systems where you need to regenerate static pages in the background:

```php
$registry->register('page', function($params) {
    return renderPage($params['url']);
});

// When content changes, queue regeneration
$dispatcher->dispatch([
    'callback_name' => 'page',
    'params' => ['url' => '/about']
]);
```

### Email Generation

Generate emails asynchronously:

```php
$registry->register('welcome_email', function($params) {
    return renderEmail('welcome', $params);
});

// Queue email generation
$dispatcher->dispatch([
    'callback_name' => 'welcome_email',
    'params' => ['user_id' => 123]
]);
```

### Report Generation

Generate heavy reports in the background:

```php
$registry->register('sales_report', function($params) {
    return generateSalesReport($params['month'], $params['year']);
});
```

### API Response Caching

Pre-generate and cache API responses:

```php
$registry->register('api.products', function($params) {
    return json_encode(fetchProducts($params));
});
```

## Troubleshooting

### "Callback not found" errors

**Problem:** Callback registered in main process but not in worker.

**Solution:** Ensure both processes call the same initialization function.

### Callbacks not updating

**Problem:** Changed callback code but output hasn't changed.

**Solution:** Clear cache and restart workers to reload callback code.

### Memory issues with large callbacks

**Problem:** Callbacks with large closures using too much memory.

**Solution:** Keep callbacks lean - fetch data inside callback, not in closure.

## Contributing

Contributions welcome! Please ensure:

1. All tests pass
2. New features have tests
3. Code follows PSR-12 style guide
4. Documentation is updated

## License

MIT License - see LICENSE file for details

## Support

- Documentation: See this README
- Issues: GitHub Issues
- Examples: See `example_*.php` files

## Changelog

### v1.0.0 (2024-12-13)
- Initial release
- Core functionality
- WordPress integration
- Laravel integration
- Comprehensive test suite
