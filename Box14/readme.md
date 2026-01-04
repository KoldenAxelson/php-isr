# Logger

A simple, maintainable PHP logger with multiple output handlers and efficient level filtering. Designed for production use with minimal overhead.

## What It Does

Logs messages at different severity levels (debug, info, warning, error) to various outputs (file, syslog, null). Filters messages by minimum level and formats them consistently for easy reading.

## Installation

```php
require_once 'Logger.php';
$logger = new Logger();
```

## Basic Usage

```php
$logger = new Logger('file', 'info', ['path' => '/var/log/app.log']);

$result = $logger->log([
    'level' => 'error',
    'message' => 'Cache regeneration failed',
    'context' => [
        'url' => '/blog/post-123',
        'error' => 'Timeout after 30s'
    ]
]);

// Returns:
// [
//     'logged' => true,
//     'timestamp' => 1702651800,
//     'formatted' => '[2024-01-15 10:30:00] ERROR: Cache regeneration failed | url: /blog/post-123, error: Timeout after 30s'
// ]
```

## Log Levels

Four levels in order of severity:

1. **DEBUG** (100) - Detailed debugging information
2. **INFO** (200) - Informational messages
3. **WARNING** (300) - Warning messages
4. **ERROR** (400) - Error messages

```php
$logger = new Logger('file', 'warning'); // Only log WARNING and ERROR

$logger->debug('Debug info');      // Not logged (filtered)
$logger->info('Info message');     // Not logged (filtered)
$logger->warning('Warning!');      // Logged ✓
$logger->error('Error occurred');  // Logged ✓
```

## Output Handlers

### File Handler

Writes logs to a file with buffering for performance:

```php
$logger = new Logger('file', 'debug', [
    'path' => '/var/log/app.log'
]);

$logger->info('Application started');
$logger->flush(); // Force write buffered logs
```

**Buffering behavior:**
- Logs are buffered in memory (100 messages by default)
- Auto-flushes when buffer is full
- Auto-flushes on logger destruction
- Call `flush()` to force immediate write

### Syslog Handler

Writes to system log:

```php
$logger = new Logger('syslog', 'info', [
    'identity' => 'my-app',
    'facility' => LOG_USER
]);

$logger->warning('High memory usage', ['memory_mb' => 512]);
```

### Null Handler

Discards all logs (useful for testing):

```php
$logger = new Logger('null');

$logger->error('This goes nowhere');
// Still returns formatted message for inspection
```

## Input Format

```php
[
    'level' => string,      // 'debug', 'info', 'warning', or 'error'
    'message' => string,    // Log message
    'context' => array      // Optional: Additional context data
]
```

## Output Format

```php
[
    'logged' => bool,       // True if message was logged
    'timestamp' => int,     // Unix timestamp
    'formatted' => string   // Formatted log line
]
```

**Log format:**
```
[YYYY-MM-DD HH:MM:SS] LEVEL: message | key: value, key: value
```

**Example:**
```
[2024-01-15 10:30:00] ERROR: Database connection failed | host: db.example.com, timeout: 30
```

## Convenience Methods

Shorthand for common logging patterns:

```php
$logger->debug('Debug message', ['user_id' => 123]);
$logger->info('User logged in', ['username' => 'alice']);
$logger->warning('High CPU usage', ['cpu_percent' => 95]);
$logger->error('Payment failed', ['order_id' => 'ORD-123']);
```

Equivalent to:

```php
$logger->log(['level' => 'debug', 'message' => 'Debug message', 'context' => ['user_id' => 123]]);
```

## Context Handling

Context values are automatically converted to strings:

```php
$logger->info('Context types', [
    'string' => 'text',
    'int' => 42,
    'float' => 3.14,
    'bool' => true,
    'null' => null,
    'array' => [1, 2, 3]
]);

// Output:
// [2024-01-15 10:30:00] INFO: Context types | string: text, int: 42, float: 3.14, bool: true, null: null, array: [1,2,3]
```

## Batch Processing

Log multiple messages efficiently:

```php
$logs = [
    ['level' => 'info', 'message' => 'Request started'],
    ['level' => 'debug', 'message' => 'Query executed', 'context' => ['duration_ms' => 45]],
    ['level' => 'info', 'message' => 'Request completed']
];

$results = $logger->logBatch($logs);
// Returns array of results with preserved indices
```

## Dynamic Level Control

Change minimum log level at runtime:

```php
$logger = new Logger('file', 'info');

$logger->debug('Not logged');  // Filtered

$logger->setMinLevel('debug');
$logger->debug('Now logged!'); // Logged ✓

// Check current level
$level = $logger->getMinLevel(); // Returns 100 (DEBUG constant)
```

## Use Cases

### Web Request Logging

```php
$logger = new Logger('file', 'info', ['path' => '/var/log/access.log']);

$logger->info('HTTP request', [
    'method' => $request->method(),
    'path' => $request->path(),
    'status' => $response->status(),
    'duration_ms' => $duration,
    'ip' => $request->ip()
]);
```

### Error Tracking

```php
try {
    // Some operation
} catch (Exception $e) {
    $logger->error('Unhandled exception', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
```

### Application Monitoring

```php
// Log system metrics every minute
$logger->info('System metrics', [
    'cpu_usage' => $cpu,
    'memory_mb' => $memory,
    'active_connections' => $connections,
    'queue_size' => $queueSize
]);
```

### Debug Mode

```php
// Development environment
if (getenv('APP_ENV') === 'development') {
    $logger = new Logger('file', 'debug');
} else {
    // Production - only warnings and errors
    $logger = new Logger('file', 'warning');
}
```

### API Gateway Logging

```php
$logger->info('API call', [
    'endpoint' => '/api/v1/users',
    'api_key' => substr($apiKey, 0, 8) . '...',
    'response_time_ms' => $responseTime,
    'rate_limit_remaining' => $rateLimitRemaining
]);
```

### Cache Operations

```php
$logger->debug('Cache lookup', [
    'key' => $cacheKey,
    'hit' => $hit,
    'ttl' => $ttl
]);

if (!$hit) {
    $logger->warning('Cache miss', [
        'key' => $cacheKey,
        'regeneration_time_ms' => $regenTime
    ]);
}
```

### Database Query Logging

```php
$logger->debug('SQL query', [
    'query' => $sql,
    'duration_ms' => $duration,
    'rows_affected' => $rowCount
]);

if ($duration > 1000) {
    $logger->warning('Slow query detected', [
        'query' => substr($sql, 0, 100),
        'duration_ms' => $duration
    ]);
}
```

## Performance

**Target:** Log 10,000 messages in <100ms

Typical performance on modern hardware:
- **Null handler:** ~30-40ms for 10,000 logs (~250,000 logs/sec)
- **File handler:** ~50-70ms for 10,000 logs (~140,000 logs/sec)
- **Syslog handler:** ~80-100ms for 10,000 logs (~100,000 logs/sec)

### Running Performance Tests

```bash
phpunit LoggerTest.php --filter testPerformanceBenchmark
```

Expected output:
```
✓ Performance: 10,000 logs in ~35ms
✓ File handler: 10,000 logs in ~55ms
✓ Memory: 0.5MB increase for 10,000 logs
```

### Performance Tips

1. **Use batch logging** for multiple messages:
```php
// Slower: Multiple individual calls
for ($i = 0; $i < 1000; $i++) {
    $logger->info("Message $i");
}

// Faster: Single batch call
$messages = array_map(fn($i) => ['level' => 'info', 'message' => "Message $i"], range(1, 1000));
$logger->logBatch($messages);
```

2. **Set appropriate minimum level** to filter unnecessary logs:
```php
// Production: Only warnings and errors
$logger = new Logger('file', 'warning');

// Saves CPU by skipping debug/info formatting
```

3. **Let file handler buffer** for better performance:
```php
// Don't do this in hot loops:
for ($i = 0; $i < 1000; $i++) {
    $logger->info("Message");
    $logger->flush(); // Forces write every iteration - slow!
}

// Do this instead:
for ($i = 0; $i < 1000; $i++) {
    $logger->info("Message");
}
$logger->flush(); // Single flush at end - fast!
```

## Memory Management

The logger is designed for long-running processes:

- **File handler buffering:** Fixed buffer size (100 messages)
- **No memory leaks:** Auto-flushes prevent unbounded memory growth
- **Minimal overhead:** ~50 bytes per log message (before flush)

```php
// Safe for long-running workers
while (true) {
    processJob($job);
    $logger->info('Job completed', ['job_id' => $job->id]);
    // Buffer auto-flushes every 100 logs
}
```

## Testing

### Requirements
- PHP 7.4+
- PHPUnit 9.0+

### Run All Tests

```bash
phpunit LoggerTest.php
```

Expected output:
```
OK (45 tests, 150+ assertions)
✓ Performance: 10,000 logs in ~35ms
✓ File handler: 10,000 logs in ~55ms
✓ Memory: 0.5MB increase for 10,000 logs
```

### Test Coverage

- ✅ Basic logging functionality
- ✅ All log levels (debug, info, warning, error)
- ✅ Level filtering
- ✅ Context handling (all data types)
- ✅ File handler with buffering
- ✅ Syslog handler
- ✅ Null handler
- ✅ Batch processing
- ✅ Message formatting
- ✅ Edge cases (empty messages, special characters, unicode)
- ✅ Performance benchmarks
- ✅ Memory usage
- ✅ Real-world scenarios

## Design Decisions

### Why These Four Levels?

DEBUG, INFO, WARNING, ERROR cover 99% of logging needs:
- **DEBUG:** Detailed troubleshooting info
- **INFO:** Normal operational events
- **WARNING:** Unexpected but handled situations
- **ERROR:** Failures requiring attention

Adding more levels (TRACE, NOTICE, CRITICAL, etc.) adds complexity without meaningful benefit for most applications.

### Why Buffering?

Writing to disk on every log call is slow. Buffering reduces system calls:
- **Without buffering:** 10,000 logs = 10,000 disk writes (~500ms)
- **With buffering:** 10,000 logs = ~100 disk writes (~50ms)

The buffer size (100) balances performance with memory usage.

### Why Simple Format?

The log format `[timestamp] LEVEL: message | context` is:
- **Human-readable:** Easy to scan with grep/tail
- **Machine-parseable:** Simple regex extraction
- **Compact:** No JSON overhead for simple messages

```php
// Human-readable
[2024-01-15 10:30:00] ERROR: Database timeout | host: db.example.com

// vs JSON (harder to read)
{"timestamp":"2024-01-15T10:30:00Z","level":"ERROR","message":"Database timeout","context":{"host":"db.example.com"}}
```

### Why Handler Pattern?

Different environments need different outputs:
- **Development:** File (easy to tail -f)
- **Production:** Syslog (centralized logging)
- **Testing:** Null (don't pollute logs)

The handler pattern makes this flexible without complexity.

## Limitations

### What This Is NOT

- ❌ Not a log aggregation system (use ELK, Splunk, etc.)
- ❌ Not a structured logging library (logs are text, not JSON)
- ❌ Not a distributed tracing system (use OpenTelemetry)
- ❌ Not a log rotation tool (use logrotate)

### What This IS

- ✅ A simple way to log messages in PHP applications
- ✅ Fast enough for production use
- ✅ Easy to understand and maintain
- ✅ Well-tested and reliable

## Common Issues

### Logs Not Appearing in File

**Check file permissions:**
```bash
# Ensure web server can write to log file
chmod 666 /var/log/app.log
# Or create with correct owner
sudo touch /var/log/app.log
sudo chown www-data:www-data /var/log/app.log
```

**Check minimum level:**
```php
// If minimum is 'error', info/debug won't be logged
$logger = new Logger('file', 'error');
$logger->info('Not logged!'); // Filtered out

// Fix: Lower the minimum level
$logger->setMinLevel('info');
```

**Force flush if needed:**
```php
$logger->info('Message');
$logger->flush(); // Force write immediately
```

### Performance Issues

**Use batch logging:**
```php
// Slow
foreach ($items as $item) {
    $logger->info("Processing $item");
}

// Fast
$logs = array_map(fn($item) => ['level' => 'info', 'message' => "Processing $item"], $items);
$logger->logBatch($logs);
```

**Increase minimum level:**
```php
// Logs everything (slow if many debug messages)
$logger = new Logger('file', 'debug');

// Only errors (fast)
$logger = new Logger('file', 'error');
```

### Understanding Levels

Messages below minimum level are filtered:

```php
$logger = new Logger('file', 'warning');

// These are filtered (not logged):
$logger->debug('...');  // 100 < 300
$logger->info('...');   // 200 < 300

// These are logged:
$logger->warning('...'); // 300 >= 300
$logger->error('...');   // 400 >= 300
```

### File Handler Not Flushing

The file handler buffers for performance. Messages appear in the file:
- When buffer is full (100 messages)
- When `flush()` is called
- When logger is destroyed

```php
// Logs might not appear immediately
$logger->info('Message 1');
// ...still in buffer...

// Force write
$logger->flush();
// ...now in file
```

## Requirements

- PHP 7.4 or higher
- No external dependencies (uses only PHP built-ins)

## Code Quality

This is a simplified implementation focused on:
- Clarity over cleverness
- Performance within constraints (<100ms for 10k logs)
- Maintainability over feature completeness

The code intentionally avoids:
- Over-engineering (log rotation, compression, remote handlers)
- Premature optimization (micro-optimizations for marginal gains)
- Feature bloat (JSON output, custom formatters, log processors)

## Extending the Logger

### Custom Handler

Create a new handler implementing the `LogHandler` interface:

```php
class EmailHandler implements LogHandler
{
    private $email;
    
    public function __construct(string $email)
    {
        $this->email = $email;
    }
    
    public function write(string $message): void
    {
        mail($this->email, 'Application Error', $message);
    }
    
    public function flush(): void
    {
        // No buffering needed
    }
}

// Use it by modifying createHandler() in Logger.php
```

### Custom Format

Modify the `format()` method in `Logger.php`:

```php
private function format(int $level, string $message, array $context, int $timestamp): string
{
    // JSON format
    return json_encode([
        'timestamp' => $timestamp,
        'level' => self::LEVEL_NAMES[$level],
        'message' => $message,
        'context' => $context
    ]);
}
```

### Additional Levels

Add custom levels to the constants:

```php
public const CRITICAL = 500;

private const LEVEL_NAMES = [
    self::DEBUG => 'DEBUG',
    self::INFO => 'INFO',
    self::WARNING => 'WARNING',
    self::ERROR => 'ERROR',
    self::CRITICAL => 'CRITICAL',
];
```

## Contributing

This is intentionally kept simple. If you need additional features:

1. **For custom handlers:** Implement `LogHandler` interface
2. **For custom formats:** Modify `format()` method
3. **For additional levels:** Add to constants and modify `levelNameToInt()`

The code is designed to be easy to modify for your specific needs.

## License

Use freely in your projects. Modify as needed.

## Support

- Check the test suite for usage examples
- Review the code comments for implementation details
- Performance issues? Run the benchmark tests to identify bottlenecks

## Changelog

### Version 1.0.0
- Four log levels: DEBUG, INFO, WARNING, ERROR
- Three output handlers: file, syslog, null
- Level filtering
- Buffered file writing for performance
- Batch logging support
- Convenience methods (debug, info, warning, error)
- Comprehensive test suite
- Performance: <100ms for 10,000 logs
- Memory safe for long-running processes
