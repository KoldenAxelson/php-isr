# Response Sender

A simple, maintainable PHP library that sends HTTP responses to the browser with appropriate headers and optional gzip compression.

## What It Does

Sends HTML content to the browser with proper HTTP status codes, headers, and optional compression. Handles the technical details of HTTP response delivery so you can focus on generating content.

## Installation

```php
require_once 'ResponseSender.php';
$sender = new ResponseSender();
```

## Basic Usage

```php
$sender = new ResponseSender();

$input = [
    'html' => '<html><body>Hello World</body></html>',
    'status_code' => 200,
    'headers' => [
        'Content-Type' => 'text/html',
        'X-Cache' => 'HIT'
    ],
    'compress' => true
];

$result = $sender->send($input);
// Returns: [
//     'sent' => true,
//     'bytes_sent' => 1024,
//     'compressed' => true
// ]
```

## PSR-7 Support

ResponseSender supports PSR-7 `ResponseInterface` objects:

```php
use Psr\Http\Message\ResponseInterface;

// Create a PSR-7 response (using any PSR-7 library)
$response = new Response(200, [
    'Content-Type' => 'text/html',
    'X-Cache' => 'HIT'
], '<html><body>Hello World</body></html>');

// Send it
$result = $sender->sendPsr7($response, $compress = true);
```

**Works with popular libraries:**
- Guzzle PSR-7
- Slim Framework
- Laminas Diactoros
- Nyholm PSR-7
- Any PSR-7 implementation

**Example with Slim Framework:**
```php
$app->get('/page', function ($request, $response) use ($sender) {
    $html = generatePageContent();
    
    $response = $response
        ->withStatus(200)
        ->withHeader('Content-Type', 'text/html')
        ->withHeader('X-Cache', 'HIT');
    
    $response->getBody()->write($html);
    
    // Send with compression
    $sender->sendPsr7($response, true);
    
    return $response;
});
```

## Input Format

```php
[
    'html' => string,              // Required: HTML content to send
    'status_code' => int,          // Optional: HTTP status code (default: 200)
    'headers' => [                 // Optional: Custom headers
        'Header-Name' => 'value',
        // ... more headers
    ],
    'compress' => bool             // Optional: Enable gzip compression (default: false)
]
```

## Features

### HTTP Status Codes

Send any valid HTTP status code (100-599):

```php
// Success
['status_code' => 200]  // OK
['status_code' => 201]  // Created
['status_code' => 204]  // No Content

// Redirects
['status_code' => 301]  // Moved Permanently
['status_code' => 302]  // Found
['status_code' => 304]  // Not Modified

// Client Errors
['status_code' => 400]  // Bad Request
['status_code' => 404]  // Not Found

// Server Errors
['status_code' => 500]  // Internal Server Error
['status_code' => 503]  // Service Unavailable
```

### Custom Headers

Add any HTTP headers you need:

```php
$input = [
    'html' => '<html>...</html>',
    'headers' => [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'public, max-age=3600',
        'X-Cache' => 'HIT',
        'X-Cache-Key' => 'abc123',
        'ETag' => '"33a64df551425fcc55e4d42a148795d9f25f89d4"'
    ]
];

$sender->send($input);
```

**Automatic headers added:**
- `Content-Length`: Automatically calculated from content size
- `Content-Encoding`: Added when compression is used
- `Vary`: Set to `Accept-Encoding` when compression is used

### Gzip Compression

Automatically compress large responses to save bandwidth:

```php
$input = [
    'html' => '<html>... large content ...</html>',
    'compress' => true  // Enable compression
];

$result = $sender->send($input);

if ($result['compressed']) {
    echo "Saved " . ($originalSize - $result['bytes_sent']) . " bytes!";
}
```

**Compression requirements:**
1. Content must be >1KB (overhead not worth it for small content)
2. Client must support gzip (checks `Accept-Encoding` header)
3. Compressed version must actually be smaller than original

**Accept-Encoding header checking:**
```php
// Client sends: Accept-Encoding: gzip, deflate, br
// → Compression enabled ✓

// Client sends: Accept-Encoding: GZIP, DEFLATE, BR  
// → Compression enabled ✓ (case-insensitive per HTTP spec)

// Client sends: Accept-Encoding: deflate, br  
// → Compression disabled (no gzip support)

// Client sends no Accept-Encoding header
// → Compression disabled (assume no support)
```

**Note:** Matching is case-insensitive as per HTTP specification (RFC 7230).

**Compression behavior:**
- Uses gzip level 6 (good balance of speed and compression)
- Typically achieves 60-80% reduction for HTML
- Automatically adds `Content-Encoding: gzip` header
- Automatically adds `Vary: Accept-Encoding` header for proper caching

**Example compression ratios:**
```php
// Typical HTML with repetitive structure
Original: 50KB
Compressed: 12KB (76% reduction)

// Highly compressible (lots of whitespace/repetition)
Original: 100KB
Compressed: 8KB (92% reduction)

// Already optimized (minified)
Original: 20KB
Compressed: 15KB (25% reduction)
```

### Testing Without Sending

Use `prepare()` to test response preparation without actually sending:

```php
$prepared = $sender->prepare([
    'html' => '<html>...</html>',
    'status_code' => 200,
    'headers' => ['X-Test' => 'Value'],
    'compress' => true
]);

// Inspect what would be sent
print_r($prepared);
// [
//     'status_code' => 200,
//     'headers' => [...],
//     'content' => '...',  // Possibly compressed
//     'bytes_sent' => 1234,
//     'compressed' => true
// ]
```

This is useful for:
- Unit testing
- Debugging response issues
- Calculating bandwidth usage
- Validating headers before sending

## Use Cases

### Basic ISR Response

```php
// After generating or fetching cached content
$html = $generator->generate($request);

$sender->send([
    'html' => $html,
    'status_code' => 200,
    'headers' => [
        'Content-Type' => 'text/html; charset=utf-8',
        'X-Cache' => 'MISS'  // Fresh generation
    ],
    'compress' => true
]);
```

### Cached Response

```php
$cached = $cache->get($cacheKey);

$sender->send([
    'html' => $cached['html'],
    'status_code' => 200,
    'headers' => [
        'Content-Type' => 'text/html; charset=utf-8',
        'X-Cache' => 'HIT',
        'X-Cache-Key' => $cacheKey,
        'Age' => (time() - $cached['timestamp']),
        'Cache-Control' => 'public, max-age=3600'
    ],
    'compress' => true
]);
```

### Stale-While-Revalidate

```php
if ($cache->isStale($cacheKey)) {
    // Serve stale content
    $sender->send([
        'html' => $cache->get($cacheKey)['html'],
        'status_code' => 200,
        'headers' => [
            'X-Cache' => 'STALE',
            'X-Revalidating' => 'true'
        ],
        'compress' => true
    ]);
    
    // Trigger background revalidation
    $dispatcher->dispatch($regenerateJob);
}
```

### Error Pages

```php
$sender->send([
    'html' => $errorPageGenerator->generate(404),
    'status_code' => 404,
    'headers' => [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-cache'
    ],
    'compress' => true
]);
```

### Redirect

```php
$sender->send([
    'html' => '',  // No content for redirect
    'status_code' => 302,
    'headers' => [
        'Location' => 'https://example.com/new-page'
    ],
    'compress' => false
]);
```

### API Response (JSON)

```php
$json = json_encode(['status' => 'success', 'data' => $data]);

$sender->send([
    'html' => $json,  // Works for any text content, not just HTML
    'status_code' => 200,
    'headers' => [
        'Content-Type' => 'application/json',
        'Access-Control-Allow-Origin' => '*'
    ],
    'compress' => true
]);
```

## Performance

**Target:** Send response in <1ms (without compression)

Typical performance on modern hardware:
- **<0.1ms** for small responses (<10KB)
- **<1ms** for medium responses (10-100KB)
- **<5ms** for large responses with compression (100KB-1MB)

### Running Performance Tests

```bash
phpunit ResponseSenderTest.php --filter testPerformanceBenchmark
```

Expected output:
```
✓ Performance: 10,000 sends in ~800ms (0.08ms per send)
✓ Compression: 1,000 sends in ~3500ms (3.5ms per send)
```

### Performance Tips

1. **Disable compression for small content** - Overhead isn't worth it for <1KB
2. **Use compression for large content** - 60-80% bandwidth savings
3. **Cache compressed output** - Don't re-compress on every request
4. **Adjust compression level** - Default is 6 (good balance), range is 1-9

## Error Handling

### Invalid Status Code

```php
try {
    $sender->send([
        'html' => '<html></html>',
        'status_code' => 999  // Invalid
    ]);
} catch (InvalidArgumentException $e) {
    // Handle error: "Invalid status code: 999"
}
```

### Headers Already Sent

```php
try {
    echo "Some output";  // Output already started
    
    $sender->send([
        'html' => '<html></html>',
        'status_code' => 200
    ]);
} catch (RuntimeException $e) {
    // Handle error: "Headers already sent"
    // This happens when output has already been sent to browser
}
```

**Prevention:**
- Use output buffering (`ob_start()`)
- Don't echo/print before sending headers
- Check `headers_sent()` before attempting to send

## Testing

### Requirements
- PHP 7.4+
- PHPUnit 9.0+

### Run All Tests

```bash
phpunit ResponseSenderTest.php
```

Expected output:
```
OK (35 tests, 120+ assertions)
✓ Performance: 10,000 sends in ~800ms
✓ Compression: 1,000 sends in ~3500ms
```

### Test Coverage

- ✅ Basic response sending
- ✅ Status code handling (valid/invalid)
- ✅ Custom headers
- ✅ Gzip compression (enable/disable)
- ✅ Small content (no compression)
- ✅ Large content (with compression)
- ✅ Compression correctness (decompress test)
- ✅ Error handling (invalid codes, headers sent)
- ✅ Default values
- ✅ Edge cases (empty content, special characters, multibyte)
- ✅ Performance benchmarks
- ✅ Integration scenarios

## Design Decisions

### Why Check Accept-Encoding?

**The problem:** Sending gzip-encoded content to a client that doesn't support it results in corrupted/unreadable responses.

**The solution:** Check the `Accept-Encoding` header before compressing:
```php
// Client supports gzip → compress ✓
Accept-Encoding: gzip, deflate, br

// Client doesn't support gzip → don't compress
Accept-Encoding: deflate, br
```

**In practice:** This rarely matters because:
- Every browser since IE6 (2001) supports gzip
- Most HTTP clients support gzip by default
- The overhead is negligible (simple string check)

But for correctness and compatibility with unusual clients (custom scrapers, old embedded systems), we check.

### Why Support PSR-7?

**Reason 1: Interoperability**  
Many PHP frameworks and libraries use PSR-7 as the standard for HTTP messages. Supporting it means ResponseSender works seamlessly with:
- Slim Framework
- Mezzio (formerly Zend Expressive)
- Symfony HttpFoundation (via bridge)
- Any PSR-7 middleware

**Reason 2: Type Safety**  
PSR-7 `ResponseInterface` is strongly typed. You can't accidentally pass invalid data.

**Reason 3: No Dependencies**  
We don't require PSR-7 as a dependency. The `sendPsr7()` method uses duck-typing to check for required methods, so it works with any PSR-7 implementation without requiring the interfaces package.

**Design choice:** Keep both interfaces (array and PSR-7) to support simple use cases and framework integration.

### Why Gzip Instead of Brotli?

Gzip is universally supported and fast enough. Brotli offers better compression but:
- Not available in all PHP installations
- Slower compression (not worth it for dynamic content)
- Minimal real-world benefit for ISR use case

If you need Brotli, it's easy to add as an option.

### Why Level 6 Compression?

Compression levels 1-9 trade speed vs compression ratio:
- Level 1: Fast but weak compression (~40% reduction)
- Level 6: Good balance (~70% reduction, still fast)
- Level 9: Best compression (~75% reduction, much slower)

Level 6 gives 90% of the benefit for 50% of the cost.

### Why 1KB Minimum for Compression?

Compression overhead (CPU time + gzip headers) isn't worth it for small content:
- <1KB: Overhead > Savings
- 1-10KB: Break even
- >10KB: Clear win

### Why Not Stream Large Responses?

For ISR use cases, responses are typically:
- Pre-generated (already in memory)
- Small to medium (<1MB)
- Fully available before sending

Streaming adds complexity without significant benefit. If you need streaming, consider using `ob_start('ob_gzhandler')` directly.

### Why Wrapper Methods?

The `protected` wrapper methods (`sendHeader()`, `sendOutput()`, etc.) allow:
- Unit testing (override in test class)
- Mocking
- Logging/debugging
- Future extension

Without them, testing actual header sending is impossible in PHPUnit.

## Limitations

### What This Is NOT

- ❌ Not a full HTTP framework (use Symfony, Laravel for that)
- ❌ Not a response builder (just sends what you give it)
- ❌ Not a streaming solution (loads full content in memory)
- ❌ Not a middleware system (for that, use PSR-15)

### What This IS

- ✅ A simple way to send HTTP responses
- ✅ PSR-7 compatible (works with modern frameworks)
- ✅ Production-ready for ISR applications
- ✅ Easy to understand and maintain
- ✅ Well-tested and reliable
- ✅ Proper content negotiation (checks Accept-Encoding)

## Common Issues

### Compression Not Working

**Check content size:**
```php
$html = '<html>Small</html>';  // Only ~18 bytes

$result = $sender->send([
    'html' => $html,
    'compress' => true
]);

// compressed = false (too small)
```

Content must be >1KB to compress.

**Check Accept-Encoding header:**
```php
// If client doesn't support gzip
$_SERVER['HTTP_ACCEPT_ENCODING'] = 'deflate, br';  // No gzip!

$result = $sender->send([
    'html' => $largeHtml,
    'compress' => true
]);

// compressed = false (client doesn't accept gzip)
```

In testing, you can override this:
```php
class TestSender extends ResponseSender {
    protected function getAcceptEncoding(): string {
        return 'gzip, deflate';
    }
}
```

**Check if compressed version is actually smaller:**
```php
// Already compressed/binary data
$html = file_get_contents('image.jpg');

$result = $sender->send([
    'html' => $html,
    'compress' => true
]);

// compressed = false (compressed version wasn't smaller)
```

### Headers Already Sent Error

**Problem:**
```php
echo "Debug info";  // Outputs to browser

$sender->send([...]);  // Error: Headers already sent
```

**Solution:**
```php
ob_start();  // Start output buffering

echo "Debug info";  // Buffered

$sender->send([...]);  // Works!

ob_end_flush();  // Send buffered output
```

### Bytes Sent Doesn't Match Original

When compression is enabled:
```php
$html = str_repeat('<p>Test</p>', 1000);  // 11,000 bytes

$result = $sender->send([
    'html' => $html,
    'compress' => true
]);

echo $result['bytes_sent'];  // ~2,500 bytes (compressed!)
```

This is expected - `bytes_sent` reflects actual bytes sent to browser.

### Testing Is Difficult

Use the `prepare()` method for testing:
```php
class MyTest extends TestCase
{
    public function testResponse()
    {
        $sender = new ResponseSender();
        
        $prepared = $sender->prepare([
            'html' => '<html></html>',
            'status_code' => 200
        ]);
        
        $this->assertEquals(200, $prepared['status_code']);
        $this->assertArrayHasKey('Content-Length', $prepared['headers']);
    }
}
```

Or extend the class and override wrapper methods (see `ResponseSenderTest.php` for example).

### Using PSR-7 Responses

If you have a PSR-7 response object:

```php
use GuzzleHttp\Psr7\Response;

$response = new Response(200, 
    ['Content-Type' => 'text/html'], 
    '<html><body>Content</body></html>'
);

// Send it directly
$sender->sendPsr7($response, $compress = true);
```

**PSR-7 benefits:**
- Works with existing PSR-7 code
- Type safety (enforces ResponseInterface)
- Better IDE support
- Framework integration (Slim, Mezzio, etc.)

**Convert array to PSR-7:**
If you prefer PSR-7 everywhere:
```php
use GuzzleHttp\Psr7\Response;

function arrayToResponse(array $input): ResponseInterface {
    return new Response(
        $input['status_code'] ?? 200,
        $input['headers'] ?? [],
        $input['html'] ?? ''
    );
}

$response = arrayToResponse($input);
$sender->sendPsr7($response, true);
```

## Advanced Usage

### Calculate Bandwidth Savings

```php
$original = '<html>... large content ...</html>';
$originalSize = strlen($original);

$result = $sender->send([
    'html' => $original,
    'compress' => true
]);

if ($result['compressed']) {
    $savings = $originalSize - $result['bytes_sent'];
    $percent = ($savings / $originalSize) * 100;
    
    echo "Saved {$savings} bytes ({$percent}% reduction)";
}
```

### Conditional Compression

```php
$compress = strlen($html) > 10000;  // Only compress if >10KB

$sender->send([
    'html' => $html,
    'compress' => $compress
]);
```

### Measure Compression Ratio

```php
$sender = new ResponseSender();

$original = str_repeat('AAAA', 10000);
$compressed = gzencode($original, 6);

$ratio = $sender->getCompressionRatio($original, $compressed);
echo "Compression ratio: " . ($ratio * 100) . "%";
// Output: "Compression ratio: 0.1%" (highly compressible)
```

### Add Security Headers

```php
$sender->send([
    'html' => $html,
    'status_code' => 200,
    'headers' => [
        'Content-Type' => 'text/html; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-XSS-Protection' => '1; mode=block',
        'Strict-Transport-Security' => 'max-age=31536000',
        'Content-Security-Policy' => "default-src 'self'"
    ],
    'compress' => true
]);
```

## Requirements

- PHP 7.4 or higher
- zlib extension (for gzip compression)
- No external dependencies for basic usage
- PSR-7 implementation (optional, for `sendPsr7()` method)

Check zlib availability:
```php
if (function_exists('gzencode')) {
    echo "Gzip compression available";
} else {
    echo "Install zlib extension for compression";
}
```

**Optional PSR-7 libraries:**
```bash
# Choose one:
composer require guzzlehttp/psr7
composer require nyholm/psr7
composer require laminas/laminas-diactoros
composer require slim/psr7
```

## Integration with ISR

Typical ISR flow with ResponseSender:

```php
// 1. Classify request
$classifier = new RequestClassifier();
$type = $classifier->classify($request);

// 2. Generate cache key
$keyGen = new CacheKeyGenerator();
$cacheKey = $keyGen->generate($request);

// 3. Check cache
$cache = new FileCacheStore();
$cached = $cache->get($cacheKey);

// 4. Determine freshness
$freshCalc = new FreshnessCalculator();
$status = $freshCalc->calculate($cached, time());

// 5. Handle based on freshness
if ($status['fresh']) {
    // Serve from cache
    $sender = new ResponseSender();
    $sender->send([
        'html' => $cached['html'],
        'status_code' => 200,
        'headers' => [
            'X-Cache' => 'HIT',
            'Age' => $status['age']
        ],
        'compress' => true
    ]);
} elseif ($status['stale']) {
    // Serve stale, revalidate in background
    $sender->send([
        'html' => $cached['html'],
        'status_code' => 200,
        'headers' => [
            'X-Cache' => 'STALE'
        ],
        'compress' => true
    ]);
    
    $dispatcher->dispatch($revalidateJob);
} else {
    // Generate fresh
    $html = $generator->generate($request);
    
    $sender->send([
        'html' => $html,
        'status_code' => 200,
        'headers' => [
            'X-Cache' => 'MISS'
        ],
        'compress' => true
    ]);
    
    $cache->set($cacheKey, $html);
}
```

## Code Quality

This is a simplified implementation focused on:
- Clarity over cleverness
- Maintainability over micro-optimization
- Practical use over feature completeness

The code intentionally avoids:
- Over-engineering (multiple compression algorithms you'll never use)
- Premature optimization (streaming for responses that fit in memory)
- Feature bloat (content negotiation, charset detection, etc.)

## Contributing

This is intentionally kept simple. If you need additional features:

1. **For Brotli compression:** Add `brotli_compress()` support alongside gzip
2. **For streaming:** Use `ob_start('ob_gzhandler')` directly
3. **For custom compression levels:** Make level configurable in input array

**Already implemented:**
- ✅ PSR-7 support (`sendPsr7()` method)
- ✅ Accept-Encoding header checking
- ✅ Gzip compression with smart detection

The code is designed to be easy to modify for your specific needs.

## License

Use freely in your projects. Modify as needed.

## Support

- Check the test suite for usage examples
- Review the code comments for implementation details
- Performance issues? Run the benchmark tests to identify bottlenecks

## Changelog

### Version 1.1.0
- **PSR-7 Support:** Added `sendPsr7()` method for PSR-7 ResponseInterface objects
- **Accept-Encoding Check:** Properly checks client `Accept-Encoding` header before compression
- **Code Refactoring:** Eliminated duplication between `send()` and `prepare()` methods
- **Improved Testing:** Added tests for Accept-Encoding and PSR-7 functionality
- **Better Documentation:** Enhanced README with PSR-7 examples and compression details

### Version 1.0.0
- Simple, focused implementation
- HTTP status code support (100-599)
- Custom header support
- Gzip compression for large content
- Automatic Content-Length calculation
- Comprehensive error handling
- Performance: <1ms for typical responses
- Well-tested with 35+ test cases
