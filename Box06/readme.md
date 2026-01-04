# Content Generator Wrapper

A simple, reliable PHP library that executes content generation callbacks, captures output, handles errors gracefully, and tracks execution time.

## What It Does

Wraps content generation functions (that echo HTML) in a safe execution context. Captures all output, handles errors without crashing, tracks timing, and returns a consistent result structure.

## Installation

```php
require_once 'ContentGenerator.php';
$generator = new ContentGenerator();
```

## Basic Usage

```php
$generator = new ContentGenerator();

$result = $generator->execute([
    'generator' => function() {
        echo '<html>';
        echo '<body>Hello World</body>';
        echo '</html>';
    },
    'timeout' => 30,
    'url' => '/blog/post-123'
]);

// Result structure:
// [
//     'success' => true,
//     'html' => '<html><body>Hello World</body></html>',
//     'generation_time_ms' => 234,
//     'error' => null
// ]
```

## Input Format

```php
[
    'generator' => callable,  // Required: Function that generates HTML
    'timeout' => int,         // Optional: Max execution time in seconds
    'url' => string,          // Optional: Context information
]
```

## Output Format

```php
[
    'success' => bool,              // True if execution succeeded
    'html' => string,               // Captured HTML output
    'generation_time_ms' => int,    // Execution time in milliseconds
    'error' => string|null          // Error message if failed, null otherwise
]
```

## Features

### Output Capture

The generator captures content from multiple sources:

```php
// Echo statements (most common)
$result = $generator->execute([
    'generator' => function() {
        echo '<html>content</html>';
    }
]);
// Result: ['html' => '<html>content</html>']

// Return statements
$result = $generator->execute([
    'generator' => function() {
        return '<html>content</html>';
    }
]);
// Result: ['html' => '<html>content</html>']

// Multiple echo statements
$result = $generator->execute([
    'generator' => function() {
        echo '<html>';
        echo '<body>Hello</body>';
        echo '</html>';
    }
]);
// Result: ['html' => '<html><body>Hello</body></html>']
```

**Note:** If a generator both echoes AND returns content, the return value takes precedence.

### Error Handling

Errors are caught and returned gracefully without crashing:

```php
$result = $generator->execute([
    'generator' => function() {
        echo '<html>';
        throw new RuntimeException('Database connection failed');
    }
]);

// Result:
// [
//     'success' => false,
//     'html' => '',                                      // Empty on error
//     'generation_time_ms' => 5,
//     'error' => 'Database connection failed'
// ]
```

**Key behaviors:**
- Exceptions are caught and returned in `error` field
- Output buffer is cleaned on error (no partial HTML)
- Generator execution stops at the exception
- Error message is user-friendly

### Timeout Detection

Track and detect when execution exceeds time limits:

```php
$result = $generator->execute([
    'generator' => function() {
        sleep(2);  // Takes 2 seconds
        echo '<html>content</html>';
    },
    'timeout' => 1  // 1 second limit
]);

// Result:
// [
//     'success' => false,
//     'html' => '',
//     'generation_time_ms' => 2000,
//     'error' => 'Timeout exceeded: 2000ms > 1s'
// ]
```

**Timeout behavior:**
- `set_time_limit()` is called before execution
- Execution is allowed to complete
- Timeout is detected after execution
- Previous time limit is restored

**Important:** PHP's `set_time_limit()` sets a script-level timeout, not a hard kill switch. The generator will complete, but timeout violation is reported.

### Timing Measurement

Execution time is always tracked:

```php
$result = $generator->execute([
    'generator' => function() {
        // ... generate content ...
    }
]);

echo "Generation took: {$result['generation_time_ms']}ms";
```

Timing is measured using `microtime(true)` for microsecond precision, rounded to nearest millisecond.

### Batch Processing

Generate multiple pieces of content efficiently:

```php
$inputs = [
    ['generator' => function() { echo '<html>Page 1</html>'; }],
    ['generator' => function() { echo '<html>Page 2</html>'; }],
    ['generator' => function() { echo '<html>Page 3</html>'; }],
];

$results = $generator->executeBatch($inputs);

// Returns array of results with preserved indices
// $results[0]['html'] => '<html>Page 1</html>'
// $results[1]['html'] => '<html>Page 2</html>'
// $results[2]['html'] => '<html>Page 3</html>'
```

**Batch behavior:**
- Each generator runs independently
- One failure doesn't stop others
- Original array indices are preserved
- Each result has full structure (success, html, timing, error)

### Fallback Support

Execute a fallback generator if primary fails:

```php
$primary = [
    'generator' => function() {
        $data = fetchFromDatabase();  // Might fail
        echo renderTemplate($data);
    }
];

$fallback = function() {
    echo '<html><body>Service temporarily unavailable</body></html>';
};

$result = $generator->executeWithFallback($primary, $fallback);

// If primary fails, fallback is executed automatically
// Result will contain fallback's output
```

### Output Verification

Verify a generator produces non-empty output:

```php
$goodGenerator = function() {
    echo '<html>content</html>';
};

if ($generator->verifyOutput($goodGenerator)) {
    echo "Generator produces valid output";
}

$badGenerator = function() {
    echo '   ';  // Only whitespace
};

if (!$generator->verifyOutput($badGenerator)) {
    echo "Generator produces no meaningful output";
}
```

## Use Cases

### CDN Content Generation

```php
$cacheKey = generateCacheKey($url, $context);
$cachedContent = $cdn->get($cacheKey);

if (!$cachedContent) {
    $result = $generator->execute([
        'generator' => function() use ($url, $context) {
            echo renderPage($url, $context);
        },
        'timeout' => 30,
        'url' => $url
    ]);
    
    if ($result['success']) {
        $cdn->set($cacheKey, $result['html'], 3600);
        echo $result['html'];
    } else {
        // Handle error, maybe serve fallback
        logError($result['error']);
    }
}
```

### Template Rendering with Error Recovery

```php
$result = $generator->executeWithFallback(
    ['generator' => function() use ($data) {
        echo renderComplexTemplate($data);
    }],
    function() {
        echo '<html><body>Error rendering page</body></html>';
    }
);

echo $result['html'];  // Always have something to show
```

### Performance Monitoring

```php
$result = $generator->execute([
    'generator' => function() {
        echo generateDashboard();
    },
    'url' => '/dashboard'
]);

// Log slow pages
if ($result['generation_time_ms'] > 1000) {
    logSlowPage($result['url'], $result['generation_time_ms']);
}

echo $result['html'];
```

### A/B Test Content Generation

```php
$variant = $user->getABTestVariant();

$result = $generator->execute([
    'generator' => function() use ($variant) {
        if ($variant === 'A') {
            echo renderVariantA();
        } else {
            echo renderVariantB();
        }
    },
    'timeout' => 5,
    'url' => '/landing-page'
]);

trackABTestImpression($variant, $result['generation_time_ms']);
echo $result['html'];
```

### Batch Static Site Generation

```php
$pages = [
    '/home' => function() { echo renderHomePage(); },
    '/about' => function() { echo renderAboutPage(); },
    '/contact' => function() { echo renderContactPage(); },
];

$inputs = [];
foreach ($pages as $url => $pageGenerator) {
    $inputs[$url] = [
        'generator' => $pageGenerator,
        'timeout' => 10,
        'url' => $url
    ];
}

$results = $generator->executeBatch($inputs);

foreach ($results as $url => $result) {
    if ($result['success']) {
        file_put_contents("dist{$url}.html", $result['html']);
        echo "✓ Generated {$url} in {$result['generation_time_ms']}ms\n";
    } else {
        echo "✗ Failed {$url}: {$result['error']}\n";
    }
}
```

## Performance

**Target:** 
- Single execution: < 1ms overhead
- Batch of 100: < 100ms total
- Error handling: No measurable overhead

Typical performance on modern hardware:
- **~0.2-0.5ms** per execution (overhead)
- **~600-1000** executions per second
- **Batch processing:** ~100 items in <100ms

The overhead is minimal - most time is spent in your generator function, not the wrapper.

### Running Performance Tests

```bash
phpunit ContentGeneratorTest.php --filter testPerformanceBenchmark
```

Expected output:
```
✓ Performance: 1,000 executions in ~300-500ms (avg: 0.3-0.5ms/execution)
✓ Batch Performance: 100 items in ~30-50ms
```

## Error Handling Philosophy

This library prioritizes **graceful degradation** over strict failure:

1. **Always return a result** - Never throw exceptions to caller
2. **Capture errors safely** - All exceptions are caught and returned
3. **Clean output buffers** - No partial HTML on errors
4. **Preserve context** - Timing and error details always available

This means your application can:
```php
$result = $generator->execute($input);

// Always safe to access these fields
echo "Success: " . ($result['success'] ? 'Yes' : 'No');
echo "HTML: " . $result['html'];
echo "Time: {$result['generation_time_ms']}ms";
echo "Error: " . ($result['error'] ?? 'None');
```

## Design Decisions

### Why Output Buffering?

PHP's output buffering (`ob_start/ob_get_clean`) is:
- Built-in and reliable
- Zero performance overhead for this use case
- Handles all output types (echo, print, var_dump)
- Nestable for complex scenarios

Alternative approaches (capturing output streams, etc.) add complexity without benefit.

### Why Not Kill on Timeout?

PHP doesn't provide safe, portable callback termination:
- `pcntl_alarm()` requires PCNTL extension (not always available)
- Script-level timeouts affect entire request
- Forced termination can corrupt state

Instead, we:
- Set `set_time_limit()` as a safety net
- Measure actual execution time
- Report timeout violations

This is practical for web applications where:
- Most content generates quickly (< 1s)
- Timeout detection is sufficient for monitoring
- Fatal timeouts are prevented at script level

### Why Simple Error Handling?

All errors are caught and returned rather than:
- Re-throwing (forces caller to handle)
- Logging internally (opinionated)
- Suppressing (hides problems)

This gives callers full control:
```php
if (!$result['success']) {
    logger()->error($result['error']);
    return fallbackContent();
}
```

### Why Return String (Not Objects)?

The result is a simple array, not an object because:
- Arrays are lightweight and fast
- No class dependencies
- Easy to serialize for caching
- Simple to work with

If you need objects:
```php
class ContentResult {
    public static function fromArray(array $data): self {
        return new self($data['success'], $data['html'], ...);
    }
}

$result = ContentResult::fromArray($generator->execute($input));
```

## Limitations

### What This Is NOT

- ✗ Not a template engine (use Twig, Blade, etc.)
- ✗ Not an HTTP client (use Guzzle)
- ✗ Not a caching layer (use Redis, Memcached)
- ✗ Not a process manager (use Supervisor)
- ✗ Not a hard timeout enforcer (use external process management)

### What This IS

- ✓ A safe wrapper for content generation callbacks
- ✓ An output buffer manager with error handling
- ✓ A timing and monitoring utility
- ✓ A consistent interface for content generation
- ✓ Production-ready for web applications

## Common Issues

### Generator Returns Empty String

**Possible causes:**

1. **Generator does nothing:**
```php
$result = $generator->execute([
    'generator' => function() {
        // Nothing here
    }
]);
// Result: ['html' => '', 'success' => true]
```

2. **Output sent before capture:**
```php
echo 'This is not captured';  // Outside generator
$result = $generator->execute([
    'generator' => function() {
        echo 'This is captured';
    }
]);
```

3. **Error occurred:**
```php
$result = $generator->execute([
    'generator' => function() {
        echo 'Start';
        throw new Exception('Oops');  // Clears output buffer
    }
]);
// Result: ['html' => '', 'success' => false, 'error' => 'Oops']
```

### Timeout Not Working

Remember: Timeout is **detection**, not **enforcement**:

```php
$result = $generator->execute([
    'generator' => function() {
        sleep(10);  // This completes
    },
    'timeout' => 1
]);

// Result:
// - Execution completes (10 seconds)
// - Timeout is detected
// - success => false
// - error => 'Timeout exceeded: 10000ms > 1s'
```

For hard timeouts, use:
- Process management (e.g., Supervisor timeout)
- Web server timeout (e.g., Nginx `fastcgi_read_timeout`)
- PHP-FPM `request_terminate_timeout`

### Memory Issues with Large Content

If generating very large content (> 100MB):

```php
ini_set('memory_limit', '256M');  // Before execution

$result = $generator->execute([
    'generator' => function() {
        // Generate large content
        for ($i = 0; $i < 1000000; $i++) {
            echo '<div>Item</div>';
        }
    }
]);
```

Or stream directly:
```php
// Don't use ContentGenerator for huge content
// Stream directly instead:
for ($i = 0; $i < 1000000; $i++) {
    echo '<div>Item</div>';
    flush();
}
```

### Nested Generators

Be careful with nested execution:

```php
// This works but is unusual:
$result = $generator->execute([
    'generator' => function() use ($generator) {
        $nested = $generator->execute([
            'generator' => function() {
                echo 'Inner';
            }
        ]);
        echo 'Outer: ' . $nested['html'];
    }
]);
// Result: ['html' => 'Outer: Inner']
```

Usually you want:
```php
function renderSection() {
    return '<section>Content</section>';
}

$result = $generator->execute([
    'generator' => function() {
        echo '<html>';
        echo renderSection();
        echo '</html>';
    }
]);
```

## Requirements

- PHP 7.4 or higher
- No external dependencies (uses only PHP built-ins)

## Code Quality

This is a focused implementation prioritizing:
- **Safety** - Never crash the application
- **Simplicity** - Easy to understand and modify
- **Reliability** - Consistent behavior in all cases

The code intentionally avoids:
- Complex timeout mechanisms (not portable)
- Custom error types (adds dependency)
- Configuration options (keep it simple)

## Testing

### Requirements
- PHP 7.4+
- PHPUnit 9.0+

### Run All Tests

```bash
phpunit ContentGeneratorTest.php
```

Expected output:
```
OK (40+ tests, 150+ assertions)
✓ Performance: 1,000 executions in ~400ms
✓ Batch Performance: 100 items in ~40ms
```

### Test Coverage

- ✅ Basic output capture (echo, return, print)
- ✅ Error handling (exceptions, invalid input)
- ✅ Timeout detection
- ✅ Batch processing
- ✅ Fallback mechanisms
- ✅ Output verification
- ✅ Timing accuracy
- ✅ Edge cases (empty, large, special chars)
- ✅ Performance benchmarks
- ✅ Consistency and isolation

## Contributing

This is intentionally kept simple. If you need additional features:

1. **For async execution:** Fork and add process management
2. **For hard timeouts:** Use external process wrapper
3. **For result caching:** Add caching layer on top

The code is designed to be easy to modify for your specific needs.

## License

Use freely in your projects. Modify as needed.

## Support

- Check the test suite for usage examples
- Review the code comments for implementation details
- Performance issues? Run the benchmark tests to identify bottlenecks

## Changelog

### Version 1.0.0
- Simple, focused implementation
- Output buffering for echo/print/return capture
- Error handling with try-catch
- Timeout detection (not enforcement)
- Batch processing support
- Fallback execution
- Output verification utility
- Comprehensive test suite
- Performance: <1ms overhead per execution
- Zero dependencies
