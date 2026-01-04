# Quick Start Guide

Get up and running with CallbackRegistry in 5 minutes!

## Installation

### Option 1: Direct Include

```php
require_once 'CallbackRegistry.php';
```

### Option 2: Composer

```bash
composer require your-vendor/callback-registry
```

## Basic Example (30 seconds)

```php
<?php
require_once 'CallbackRegistry.php';

// 1. Create registry
$registry = new CallbackRegistry();

// 2. Register callback
$registry->register('homepage', function() {
    return "<html><body>Hello World!</body></html>";
});

// 3. Retrieve and execute
$callback = $registry->get('homepage');
$html = $callback();

echo $html;
```

## Background Job Example (2 minutes)

```php
<?php
require_once 'CallbackRegistry.php';

// Initialize registry (do this in BOTH main and worker processes)
function initRegistry() {
    $registry = new CallbackRegistry();
    
    $registry->register('send_email', function($params) {
        $to = $params['to'];
        $subject = $params['subject'];
        // ... send email
        return "Email sent to {$to}";
    });
    
    return $registry;
}

// Main process - queue the job
$registry = initRegistry();
$job = [
    'callback_name' => 'send_email',
    'params' => [
        'to' => 'user@example.com',
        'subject' => 'Welcome!'
    ]
];
// dispatch($job); // Send to your queue

// Worker process - execute the job
$registry = initRegistry(); // Initialize again!
$callback = $registry->get($job['callback_name']);
$result = $callback($job['params']);
```

## Run the Demos

### Interactive Mode
```bash
php demo.php
```

### Run All Demos
```bash
php demo.php --all
```

### Run Specific Demo
```bash
php demo.php 1  # Basic usage
php demo.php 2  # Background jobs
php demo.php 3  # WordPress
php demo.php 4  # Laravel
```

### Run Tests
```bash
composer require --dev phpunit/phpunit
php demo.php --test
```

## Common Patterns

### With Parameters

```php
$registry->register('user_profile', function($params) {
    $userId = $params['user_id'];
    return renderUserProfile($userId);
});

// Later...
$html = $callback(['user_id' => 123]);
```

### With Metadata

```php
$registry->register('homepage', $callback, [
    'description' => 'Main homepage',
    'ttl' => 3600,
    'priority' => 'high'
]);

$meta = $registry->getMetadata('homepage');
$ttl = $meta['ttl']; // 3600
```

### Error Handling

```php
$callback = $registry->get('maybe_missing');

if ($callback === null) {
    // Handle missing callback
    error_log("Callback not found!");
    return fallbackContent();
}

$result = $callback($params);
```

## Real-World Use Cases

### ISR (Incremental Static Regeneration)

```php
// Register page generators
$registry->register('page', function($params) {
    return renderPage($params['url']);
});

// When content changes
dispatch([
    'callback_name' => 'page',
    'params' => ['url' => '/about']
]);
```

### Email Queue

```php
$registry->register('welcome_email', function($params) {
    return renderEmail('welcome', $params);
});

$registry->register('password_reset', function($params) {
    return renderEmail('password-reset', $params);
});
```

### API Response Caching

```php
$registry->register('api.users', function($params) {
    $users = fetchUsers($params);
    return json_encode($users);
});
```

## Next Steps

1. ✅ Review the full [README.md](README.md)
2. ✅ Run the examples: `php demo.php --all`
3. ✅ Check the [specification document](CallbackRegistry%20Specification.md)
4. ✅ Integrate into your project
5. ✅ Star the repo if you find it useful!

## Troubleshooting

### "Callback not found" in worker

**Problem:** Callback works in main process but not in worker.

**Solution:** Make sure you call the same initialization function in both processes:

```php
// bootstrap.php (loaded everywhere)
function initCallbacks() {
    $registry = new CallbackRegistry();
    $registry->register('callback1', ...);
    $registry->register('callback2', ...);
    return $registry;
}

// main.php
$registry = initCallbacks();

// worker.php
$registry = initCallbacks(); // Same initialization!
```

### "Invalid argument" when registering

**Problem:** Callback name has invalid characters.

**Solution:** Use only alphanumeric characters, underscores, hyphens, and dots:

```php
// ✅ Valid
$registry->register('homepage', ...);
$registry->register('blog_post', ...);
$registry->register('api.users.index', ...);

// ❌ Invalid
$registry->register('my callback', ...);  // spaces
$registry->register('blog/post', ...);    // slashes
```

## Support

- 📖 Full documentation: [README.md](README.md)
- 💡 Examples: Run `php demo.php`
- 🐛 Issues: GitHub Issues
- 📧 Email: your.email@example.com

---

**Ready to go?** Run `php demo.php` to see it in action!
