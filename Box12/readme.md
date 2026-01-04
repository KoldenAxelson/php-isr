# Health Monitor

A simple, fast PHP library for monitoring system health by checking disk space, permissions, PHP version, and memory availability.

## What It Does

Runs targeted health checks on your system and returns structured status information. Designed for production environments where you need fast, reliable system health monitoring.

## Installation

```php
require_once 'HealthMonitor.php';
$monitor = new HealthMonitor();
```

## Basic Usage

```php
$monitor = new HealthMonitor();

$result = $monitor->check([
    'cache_dir' => '/var/cache/isr',
    'checks' => ['disk_space', 'permissions', 'php_version', 'memory']
]);

// Result structure:
// [
//     'healthy' => true,
//     'checks' => [
//         'disk_space' => ['status' => 'ok', 'available' => '10GB', ...],
//         'permissions' => ['status' => 'ok', 'writable' => true, ...],
//         'php_version' => ['status' => 'ok', 'version' => '8.1.0', ...],
//         'memory' => ['status' => 'ok', 'available' => '128MB', ...]
//     ]
// ]

if ($result['healthy']) {
    echo "All systems operational!";
} else {
    foreach ($result['checks'] as $name => $check) {
        if ($check['status'] === 'fail') {
            echo "Failed: {$name} - {$check['error']}\n";
        }
    }
}
```

## Input Format

```php
[
    'cache_dir' => string,     // Required: Directory to check
    'checks' => [              // Optional: Specific checks to run
        'disk_space',          // Check available disk space
        'permissions',         // Check if directory is writable
        'php_version',         // Check PHP version meets minimum
        'memory'               // Check available memory
    ]
]
```

If `checks` is omitted, all checks run by default.

## Output Format

```php
[
    'healthy' => bool,         // Overall health status
    'checks' => [
        'disk_space' => [
            'status' => 'ok',
            'available' => '10GB',
            'available_bytes' => 10737418240,
            'minimum_required' => '1GB',
            'minimum_required_bytes' => 1073741824
        ],
        'permissions' => [
            'status' => 'ok',
            'writable' => true,
            'exists' => true
        ],
        'php_version' => [
            'status' => 'ok',
            'version' => '8.1.0',
            'minimum_required' => '7.4.0'
        ],
        'memory' => [
            'status' => 'ok',
            'available' => '128MB',
            'available_bytes' => 134217728,
            'limit' => '256MB',
            'limit_bytes' => 268435456,
            'usage' => '128MB',
            'usage_bytes' => 134217728,
            'minimum_required' => '64MB',
            'minimum_required_bytes' => 67108864
        ]
    ]
]
```

## Available Checks

### Disk Space Check

Verifies sufficient disk space is available:

```php
$result = $monitor->check([
    'cache_dir' => '/var/cache',
    'checks' => ['disk_space']
]);

// Returns:
// [
//     'status' => 'ok',
//     'available' => '10GB',
//     'available_bytes' => 10737418240,
//     'minimum_required' => '1GB',
//     'minimum_required_bytes' => 1073741824
// ]
```

**Default minimum:** 1GB (configurable)

**Status codes:**
- `ok` - Sufficient disk space available
- `fail` - Insufficient disk space or unable to determine

### Permissions Check

Verifies directory is writable (or can be created):

```php
$result = $monitor->check([
    'cache_dir' => '/var/cache/isr',
    'checks' => ['permissions']
]);

// For existing directory:
// [
//     'status' => 'ok',
//     'writable' => true,
//     'exists' => true
// ]

// For non-existent directory:
// [
//     'status' => 'ok',
//     'writable' => true,
//     'exists' => false,
//     'message' => 'Directory can be created'
// ]
```

**The check:**
1. If directory exists: Tests writability with actual file creation
2. If directory doesn't exist: Checks if parent directory is writable

**Status codes:**
- `ok` - Directory is writable or can be created
- `fail` - Directory is not writable or cannot be created

### PHP Version Check

Verifies PHP version meets minimum requirement:

```php
$result = $monitor->check([
    'cache_dir' => '/var/cache',
    'checks' => ['php_version']
]);

// Returns:
// [
//     'status' => 'ok',
//     'version' => '8.1.0',
//     'minimum_required' => '7.4.0'
// ]
```

**Default minimum:** PHP 7.4.0 (configurable)

**Status codes:**
- `ok` - PHP version meets or exceeds minimum
- `fail` - PHP version is below minimum

### Memory Check

Verifies sufficient memory is available:

```php
$result = $monitor->check([
    'cache_dir' => '/var/cache',
    'checks' => ['memory']
]);

// Returns:
// [
//     'status' => 'ok',
//     'available' => '128MB',
//     'available_bytes' => 134217728,
//     'limit' => '256MB',
//     'limit_bytes' => 268435456,
//     'usage' => '128MB',
//     'usage_bytes' => 134217728,
//     'minimum_required' => '64MB',
//     'minimum_required_bytes' => 67108864
// ]
```

**Default minimum:** 64MB available (configurable)

**Status codes:**
- `ok` - Sufficient memory available
- `fail` - Insufficient memory available

**Special case:** If `memory_limit` is set to `-1` (unlimited), returns:
```php
[
    'status' => 'ok',
    'available' => 'unlimited',
    'available_bytes' => -1,
    'usage' => '128MB',
    'usage_bytes' => 134217728
]
```

## Configuration

Customize thresholds when creating the monitor:

```php
$monitor = new HealthMonitor([
    'min_disk_space' => 2147483648,    // 2GB in bytes
    'min_php_version' => '8.0.0',      // Minimum PHP version
    'min_memory' => 134217728,         // 128MB in bytes
]);

$result = $monitor->check([
    'cache_dir' => '/var/cache',
]);
```

### Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `min_disk_space` | `1073741824` (1GB) | Minimum required disk space in bytes |
| `min_php_version` | `'7.4.0'` | Minimum required PHP version |
| `min_memory` | `67108864` (64MB) | Minimum required available memory in bytes |

**Memory conversions:**
- 1KB = 1,024 bytes
- 1MB = 1,048,576 bytes
- 1GB = 1,073,741,824 bytes

## Use Cases

### Application Startup Check

```php
$monitor = new HealthMonitor();

if (!$monitor->isHealthy('/var/cache/app')) {
    $result = $monitor->check(['cache_dir' => '/var/cache/app']);
    
    error_log("Startup health check failed:");
    foreach ($result['checks'] as $name => $check) {
        if ($check['status'] === 'fail') {
            error_log("  - {$name}: {$check['error']}");
        }
    }
    
    exit(1);
}

echo "Application starting...\n";
```

### Monitoring Dashboard

```php
$monitor = new HealthMonitor([
    'min_disk_space' => 5368709120,  // 5GB
    'min_memory' => 268435456,       // 256MB
]);

$result = $monitor->check([
    'cache_dir' => '/var/cache',
]);

$status = $result['healthy'] ? 'healthy' : 'unhealthy';
$color = $result['healthy'] ? 'green' : 'red';

echo "<div class='health-status {$color}'>";
echo "  <h2>System Status: {$status}</h2>";

foreach ($result['checks'] as $name => $check) {
    $badge = $check['status'] === 'ok' ? '✓' : '✗';
    echo "<p>{$badge} {$name}: {$check['status']}</p>";
    
    if ($check['status'] === 'fail') {
        echo "<p class='error'>{$check['error']}</p>";
    }
}

echo "</div>";
```

### Deployment Health Check

```php
// During deployment
$monitor = new HealthMonitor([
    'min_disk_space' => 1073741824,  // 1GB
    'min_php_version' => '8.0.0',
    'min_memory' => 134217728,       // 128MB
]);

$checks = [
    '/var/cache/app',
    '/var/cache/sessions',
    '/var/cache/templates',
];

foreach ($checks as $dir) {
    if (!$monitor->isHealthy($dir)) {
        $result = $monitor->check(['cache_dir' => $dir]);
        
        echo "❌ Deployment failed - {$dir} is not healthy\n";
        print_r($result['checks']);
        
        exit(1);
    }
    
    echo "✓ {$dir} is healthy\n";
}

echo "✓ All health checks passed - deployment can proceed\n";
```

### Batch Health Check

Check multiple systems at once:

```php
$inputs = [
    'cache' => [
        'cache_dir' => '/var/cache',
        'checks' => ['disk_space', 'permissions']
    ],
    'uploads' => [
        'cache_dir' => '/var/uploads',
        'checks' => ['disk_space', 'permissions']
    ],
    'sessions' => [
        'cache_dir' => '/var/sessions',
        'checks' => ['permissions']
    ],
];

$results = $monitor->checkBatch($inputs);

$allHealthy = true;
foreach ($results as $name => $result) {
    if (!$result['healthy']) {
        echo "⚠️  {$name} is unhealthy\n";
        $allHealthy = false;
    }
}

if ($allHealthy) {
    echo "✓ All systems healthy\n";
}
```

### Custom Health Endpoint

```php
// health.php - health check endpoint for load balancers

$monitor = new HealthMonitor();

header('Content-Type: application/json');

$result = $monitor->check([
    'cache_dir' => '/var/cache/app',
    'checks' => ['disk_space', 'permissions', 'memory']
]);

if ($result['healthy']) {
    http_response_code(200);
    echo json_encode([
        'status' => 'healthy',
        'timestamp' => time()
    ]);
} else {
    http_response_code(503);
    echo json_encode([
        'status' => 'unhealthy',
        'checks' => $result['checks'],
        'timestamp' => time()
    ]);
}
```

### Conditional Feature Enablement

```php
$monitor = new HealthMonitor();

$result = $monitor->check([
    'cache_dir' => '/var/cache',
    'checks' => ['disk_space', 'memory']
]);

// Disable caching if disk space or memory is low
$enableCaching = $result['healthy'];

if ($enableCaching) {
    $cache->enable();
} else {
    $cache->disable();
    error_log("Caching disabled due to health check failure");
}
```

## Performance

**Target:** Complete all checks in <50ms

Typical performance on modern hardware:
- **Single check:** <0.5ms
- **All 4 checks:** ~0.4-0.5ms
- **100 full health checks:** ~40-50ms

This is suitable for:
- Load balancer health endpoints (checked every 5-10 seconds)
- Application startup checks
- Request-time validation
- Monitoring dashboards

### Running Performance Tests

```bash
phpunit HealthMonitorTest.php --filter testPerformance
```

Expected output:
```
✓ Performance: 1,000 permission checks in ~50-80ms
✓ Performance: 100 full health checks in ~40-50ms (~0.4-0.5ms per check)
```

## Quick Reference

### Simple Health Check

```php
$monitor = new HealthMonitor();

if ($monitor->isHealthy('/var/cache')) {
    echo "System is healthy!";
}
```

### Specific Checks Only

```php
$result = $monitor->check([
    'cache_dir' => '/var/cache',
    'checks' => ['permissions', 'php_version']
]);
```

### Custom Thresholds

```php
$monitor = new HealthMonitor([
    'min_disk_space' => 5 * 1024 * 1024 * 1024,  // 5GB
    'min_memory' => 256 * 1024 * 1024,           // 256MB
]);
```

### Check Status

```php
$result = $monitor->check(['cache_dir' => '/var/cache']);

foreach ($result['checks'] as $name => $check) {
    echo "{$name}: {$check['status']}\n";
}
```

## Testing

### Requirements
- PHP 7.4+
- PHPUnit 9.0+

### Run All Tests

```bash
phpunit HealthMonitorTest.php
```

Expected output:
```
OK (35 tests, 100+ assertions)
✓ Performance: 100 full health checks in ~45ms (~0.45ms per check)
```

### Test Coverage

- ✅ Basic health checks
- ✅ Individual check accuracy (disk space, permissions, PHP version, memory)
- ✅ Overall health determination
- ✅ Custom configuration
- ✅ Batch processing
- ✅ Edge cases (missing paths, read-only directories, etc.)
- ✅ Performance benchmarks
- ✅ Accuracy verification

## Design Decisions

### Why Individual Checks?

Different applications need different checks. A CDN edge server might only need disk space and permissions, while an application server needs all checks. This design lets you run only what you need.

### Why Real File Creation for Permissions?

Some filesystems report `is_writable()` as true even when writes fail. We verify writability by actually creating a test file, then immediately deleting it. This ensures accuracy.

### Why Byte Values?

All metrics include both human-readable formats (`"10GB"`) and raw byte values for programmatic use. This lets you:
- Display friendly values to users
- Make precise decisions in code
- Log exact values for troubleshooting

### Why Fast Over Feature-Rich?

Health checks run frequently (every 5-10 seconds in load balancers). Speed matters more than extensive features. The implementation:
- Uses PHP built-ins (no external dependencies)
- Skips unnecessary work
- Caches nothing (health is real-time)

## Limitations

### What This Is NOT

- ❌ Not a monitoring service (use Prometheus, Datadog, etc.)
- ❌ Not a trending/alerting system (use Grafana, PagerDuty)
- ❌ Not a performance profiler (use Blackfire, XHProf)
- ❌ Not a log aggregator (use ELK stack, Splunk)

### What This IS

- ✅ A fast, simple health check library
- ✅ Suitable for load balancer endpoints
- ✅ Good for application startup validation
- ✅ Easy to understand and modify
- ✅ Zero external dependencies

### Platform Limitations

**Windows:**
- Read-only directory tests are skipped (Windows permissions work differently)
- Disk space checks work normally

**Memory Limits:**
- Accuracy depends on PHP's `memory_get_usage()` reliability
- If `memory_limit` is set to `-1`, available memory shows as "unlimited"

**Disk Space:**
- Reports free space on the filesystem containing the directory
- Cannot predict future space needs (only current availability)

## Common Issues

### Health Check Fails on Startup

**Symptom:** Application fails to start with permission errors

**Solution:**
```php
$result = $monitor->check(['cache_dir' => '/var/cache']);

if (!$result['healthy']) {
    // Print detailed information
    print_r($result['checks']);
    
    // Check specific failures
    if (isset($result['checks']['permissions'])) {
        echo "Permissions issue: ";
        print_r($result['checks']['permissions']);
    }
}
```

**Common causes:**
- Directory doesn't exist and parent isn't writable
- SELinux/AppArmor blocking writes
- Wrong user/group ownership

### Disk Space Check Fails but Drive Has Space

**Symptom:** Disk space check fails even though `df` shows free space

**Possible causes:**
1. **Reserved space:** Linux reserves 5% for root
2. **Inode exhaustion:** No free inodes even with free disk space
3. **Quota limits:** User quota exceeded

**Check:**
```bash
df -h /var/cache         # Check disk space
df -i /var/cache         # Check inode usage
quota -v                 # Check user quota
```

### Memory Check Always Fails

**Symptom:** Memory check fails even with low usage

**Solution:** Check your memory limit configuration
```php
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Current usage: " . round(memory_get_usage(true) / 1024 / 1024) . "MB\n";

// Adjust threshold if needed
$monitor = new HealthMonitor([
    'min_memory' => 32 * 1024 * 1024  // 32MB instead of 64MB
]);
```

### Performance is Slow

**Issue:** Health checks take >50ms

**Diagnosis:**
1. Run performance tests: `phpunit HealthMonitorTest.php --filter testPerformance`
2. Check which check is slow:
   ```php
   foreach (['disk_space', 'permissions', 'php_version', 'memory'] as $check) {
       $start = microtime(true);
       $monitor->check(['cache_dir' => '/var/cache', 'checks' => [$check]]);
       echo "{$check}: " . round((microtime(true) - $start) * 1000, 2) . "ms\n";
   }
   ```

**Common causes:**
- Network-mounted directories (NFS, CIFS) for disk space checks
- Slow filesystem operations
- PHP itself running slowly (check OPcache)

## Requirements

- PHP 7.4 or higher
- No external dependencies (uses only PHP built-ins)
- Write access to cache directory (for permission checks)

## Code Quality

This implementation prioritizes:
- **Speed:** <50ms for all checks
- **Accuracy:** Real-world verification (actual file creation)
- **Simplicity:** Easy to understand and modify
- **Reliability:** Comprehensive test coverage

The code intentionally avoids:
- Network calls (for speed)
- External dependencies (for portability)
- Complex thresholds (for maintainability)

## Contributing

This is intentionally kept simple. If you need additional features:

1. **For custom checks:** Add a new method following the pattern in existing checks
2. **For different thresholds:** Pass config to constructor
3. **For custom reporting:** Transform the output array as needed

The code is designed to be easy to fork and modify for your specific needs.

## License

Use freely in your projects. Modify as needed.

## Support

- Check the test suite for usage examples (`HealthMonitorTest.php`)
- Review code comments for implementation details
- Performance issues? Run benchmark tests to identify bottlenecks

## Changelog

### Version 1.0.0
- Simple, focused implementation
- Four core checks: disk space, permissions, PHP version, memory
- Performance: <50ms for all checks
- Comprehensive test suite
- Human-readable output formats
- Configurable thresholds
- Batch processing support
- Zero external dependencies
