#!/usr/bin/env php
<?php
/**
 * CallbackRegistry Demo Runner
 *
 * Runs all examples and demonstrates the functionality
 */

echo "
╔════════════════════════════════════════════════════════════════╗
║                                                                ║
║              CallbackRegistry Demo Runner                     ║
║                                                                ║
║  A solution for PHP background job callback serialization     ║
║                                                                ║
╚════════════════════════════════════════════════════════════════╝

";

function separator($title = "")
{
    echo "\n";
    echo "════════════════════════════════════════════════════════════════\n";
    if ($title) {
        echo "  {$title}\n";
        echo "════════════════════════════════════════════════════════════════\n";
    }
    echo "\n";
}

// Check if we should run tests
$runTests = in_array("--test", $argv) || in_array("-t", $argv);
$runAll = in_array("--all", $argv) || in_array("-a", $argv);
$showHelp = in_array("--help", $argv) || in_array("-h", $argv);

if ($showHelp) {
    echo "Usage: php demo.php [options]\n\n";
    echo "Options:\n";
    echo "  -h, --help     Show this help message\n";
    echo "  -t, --test     Run PHPUnit tests (requires PHPUnit)\n";
    echo "  -a, --all      Run all examples sequentially\n";
    echo "  1              Run basic usage example\n";
    echo "  2              Run background job integration\n";
    echo "  3              Run WordPress integration\n";
    echo "  4              Run Laravel integration\n\n";
    echo "Examples:\n";
    echo "  php demo.php              # Interactive menu\n";
    echo "  php demo.php --all        # Run all demos\n";
    echo "  php demo.php --test       # Run tests\n";
    echo "  php demo.php 2            # Run background jobs demo\n\n";
    exit(0);
}

if ($runTests) {
    separator("Running PHPUnit Tests");

    if (!class_exists("PHPUnit\Framework\TestCase")) {
        echo "❌ PHPUnit not found!\n\n";
        echo "To run tests, install PHPUnit:\n";
        echo "  composer require --dev phpunit/phpunit\n\n";
        exit(1);
    }

    passthru("vendor/bin/phpunit CallbackRegistryTest.php");
    exit(0);
}

// Determine which demo to run
$demo = null;

if ($runAll) {
    $demo = "all";
} elseif (isset($argv[1]) && is_numeric($argv[1])) {
    $demo = (int) $argv[1];
}

// Interactive menu if no option specified
if ($demo === null) {
    echo "Select a demo to run:\n\n";
    echo "  1. Basic Usage Example\n";
    echo "  2. Background Job Integration\n";
    echo "  3. WordPress Integration\n";
    echo "  4. Laravel Integration\n";
    echo "  5. Run All Demos\n";
    echo "  6. Exit\n\n";
    echo "Enter choice (1-6): ";

    $input = trim(fgets(STDIN));
    $demo = (int) $input;

    if ($demo === 6 || $demo === 0) {
        echo "\nGoodbye!\n\n";
        exit(0);
    }

    if ($demo === 5) {
        $demo = "all";
    }
}

// ============================================================================
// Demo 1: Basic Usage
// ============================================================================

function runBasicDemo()
{
    separator("Demo 1: Basic Usage");

    require_once __DIR__ . "/Box15/CallbackRegistry.php";

    $registry = new CallbackRegistry();

    echo "Creating a new CallbackRegistry...\n\n";

    // Register some callbacks
    echo "Registering callbacks:\n";

    $registry->register(
        "greeting",
        function ($params = []) {
            $name = $params["name"] ?? "World";
            return "Hello, {$name}!";
        },
        [
            "description" => "Simple greeting generator",
            "version" => "1.0",
        ],
    );
    echo "  ✓ Registered 'greeting'\n";

    $registry->register(
        "html_page",
        function ($params = []) {
            $title = $params["title"] ?? "Untitled";
            return "<html><head><title>{$title}</title></head><body><h1>{$title}</h1></body></html>";
        },
        [
            "description" => "HTML page generator",
        ],
    );
    echo "  ✓ Registered 'html_page'\n";

    $registry->register("json_response", function ($params = []) {
        return json_encode([
            "status" => "success",
            "data" => $params,
            "timestamp" => time(),
        ]);
    });
    echo "  ✓ Registered 'json_response'\n\n";

    // List all callbacks
    echo "Registered callbacks: " . implode(", ", $registry->list()) . "\n";
    echo "Total count: " . $registry->count() . "\n\n";

    // Retrieve and execute
    echo "Executing callbacks:\n\n";

    $greeting = $registry->get("greeting");
    echo "greeting(): " . $greeting() . "\n";
    echo "greeting(['name' => 'Alice']): " .
        $greeting(["name" => "Alice"]) .
        "\n\n";

    $htmlPage = $registry->get("html_page");
    echo "html_page(['title' => 'My Page']):\n";
    echo $htmlPage(["title" => "My Page"]) . "\n\n";

    $jsonResponse = $registry->get("json_response");
    echo "json_response(['user_id' => 123]):\n";
    echo $jsonResponse(["user_id" => 123]) . "\n\n";

    // Show metadata
    echo "Metadata for 'greeting':\n";
    print_r($registry->getMetadata("greeting"));
    echo "\n";

    // Test error handling
    echo "Testing error handling:\n";
    $missing = $registry->get("nonexistent");
    echo "  get('nonexistent'): " .
        ($missing === null ? "null ✓" : "unexpected value") .
        "\n";
    echo "  has('greeting'): " .
        ($registry->has("greeting") ? "true ✓" : "false") .
        "\n";
    echo "  has('nonexistent'): " .
        ($registry->has("nonexistent") ? "true" : "false ✓") .
        "\n\n";

    echo "✓ Basic demo completed!\n";
}

// ============================================================================
// Run Demos
// ============================================================================

if ($demo === "all" || $demo === 1) {
    runBasicDemo();
}

if ($demo === "all" || $demo === 2) {
    separator("Demo 2: Background Job Integration");
    require __DIR__ . "/example_background_jobs.php";
}

if ($demo === "all" || $demo === 3) {
    separator("Demo 3: WordPress Integration");
    require __DIR__ . "/example_wordpress.php";
}

if ($demo === "all" || $demo === 4) {
    separator("Demo 4: Laravel Integration");
    require __DIR__ . "/example_laravel.php";
}

// Final message
echo "\n";
separator();
echo "
🎉 Demo completed successfully!

Next steps:
  • Review the generated cache files in /tmp
  • Check out the test suite: php demo.php --test
  • Read the README.md for full documentation
  • Try integrating into your own project

Questions or issues?
  • See README.md for troubleshooting
  • Review the specification document
  • Check the example files for patterns

";

