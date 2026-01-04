<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "Logger.php";

/**
 * LoggerTest
 *
 * Comprehensive test suite for Logger class
 */
class LoggerTest extends TestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . "/test_" . uniqid() . ".log";
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    // ========================================
    // Basic Functionality Tests
    // ========================================

    public function testBasicLogging(): void
    {
        $logger = new Logger("null");

        $result = $logger->log([
            "level" => "error",
            "message" => "Test error",
            "context" => ["key" => "value"],
        ]);

        $this->assertTrue($result["logged"]);
        $this->assertIsInt($result["timestamp"]);
        $this->assertStringContainsString(
            "ERROR: Test error",
            $result["formatted"],
        );
        $this->assertStringContainsString("key: value", $result["formatted"]);
    }

    public function testLogOutput(): void
    {
        $logger = new Logger("null");

        $result = $logger->log([
            "level" => "error",
            "message" => "Cache regeneration failed",
            "context" => [
                "url" => "/blog/post-123",
                "error" => "Timeout after 30s",
            ],
        ]);

        $this->assertTrue($result["logged"]);
        $this->assertMatchesRegularExpression(
            "/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] ERROR: Cache regeneration failed \| url: \/blog\/post-123, error: Timeout after 30s/",
            $result["formatted"],
        );
    }

    public function testLogLevels(): void
    {
        $logger = new Logger("null", "debug");

        $levels = ["debug", "info", "warning", "error"];

        foreach ($levels as $level) {
            $result = $logger->log([
                "level" => $level,
                "message" => "Test message",
            ]);

            $this->assertTrue($result["logged"]);
            $this->assertStringContainsString(
                strtoupper($level),
                $result["formatted"],
            );
        }
    }

    public function testConvenienceMethods(): void
    {
        $logger = new Logger("null", "debug");

        $debug = $logger->debug("Debug message");
        $info = $logger->info("Info message");
        $warning = $logger->warning("Warning message");
        $error = $logger->error("Error message");

        $this->assertStringContainsString("DEBUG:", $debug["formatted"]);
        $this->assertStringContainsString("INFO:", $info["formatted"]);
        $this->assertStringContainsString("WARNING:", $warning["formatted"]);
        $this->assertStringContainsString("ERROR:", $error["formatted"]);
    }

    // ========================================
    // Level Filtering Tests
    // ========================================

    public function testLevelFiltering(): void
    {
        $logger = new Logger("null", "warning");

        // Debug and info should be filtered out
        $debug = $logger->debug("Debug message");
        $info = $logger->info("Info message");

        $this->assertFalse($debug["logged"]);
        $this->assertFalse($info["logged"]);

        // Warning and error should pass through
        $warning = $logger->warning("Warning message");
        $error = $logger->error("Error message");

        $this->assertTrue($warning["logged"]);
        $this->assertTrue($error["logged"]);
    }

    public function testMinLevelInfo(): void
    {
        $logger = new Logger("null", "info");

        $this->assertFalse($logger->debug("Debug")["logged"]);
        $this->assertTrue($logger->info("Info")["logged"]);
        $this->assertTrue($logger->warning("Warning")["logged"]);
        $this->assertTrue($logger->error("Error")["logged"]);
    }

    public function testMinLevelError(): void
    {
        $logger = new Logger("null", "error");

        $this->assertFalse($logger->debug("Debug")["logged"]);
        $this->assertFalse($logger->info("Info")["logged"]);
        $this->assertFalse($logger->warning("Warning")["logged"]);
        $this->assertTrue($logger->error("Error")["logged"]);
    }

    public function testSetMinLevel(): void
    {
        $logger = new Logger("null", "debug");
        $this->assertTrue($logger->debug("Test")["logged"]);

        $logger->setMinLevel("error");
        $this->assertFalse($logger->debug("Test")["logged"]);
        $this->assertTrue($logger->error("Test")["logged"]);
    }

    // ========================================
    // Context Handling Tests
    // ========================================

    public function testContextTypes(): void
    {
        $logger = new Logger("null");

        $result = $logger->info("Test", [
            "string" => "value",
            "int" => 42,
            "float" => 3.14,
            "bool_true" => true,
            "bool_false" => false,
            "null" => null,
            "array" => [1, 2, 3],
        ]);

        $formatted = $result["formatted"];

        $this->assertStringContainsString("string: value", $formatted);
        $this->assertStringContainsString("int: 42", $formatted);
        $this->assertStringContainsString("float: 3.14", $formatted);
        $this->assertStringContainsString("bool_true: true", $formatted);
        $this->assertStringContainsString("bool_false: false", $formatted);
        $this->assertStringContainsString("null: null", $formatted);
        $this->assertStringContainsString("array: [1,2,3]", $formatted);
    }

    public function testEmptyContext(): void
    {
        $logger = new Logger("null");

        $result = $logger->info("Message only");

        $this->assertStringNotContainsString("|", $result["formatted"]);
        $this->assertStringContainsString(
            "INFO: Message only",
            $result["formatted"],
        );
    }

    public function testComplexContext(): void
    {
        $logger = new Logger("null");

        $result = $logger->error("Database error", [
            "table" => "users",
            "operation" => "INSERT",
            "affected_rows" => 0,
            "retry_count" => 3,
        ]);

        $formatted = $result["formatted"];

        $this->assertStringContainsString("table: users", $formatted);
        $this->assertStringContainsString("operation: INSERT", $formatted);
        $this->assertStringContainsString("affected_rows: 0", $formatted);
        $this->assertStringContainsString("retry_count: 3", $formatted);
    }

    // ========================================
    // File Handler Tests
    // ========================================

    public function testFileHandler(): void
    {
        $logger = new Logger("file", "debug", ["path" => $this->testLogFile]);

        $logger->info("Test message 1");
        $logger->error("Test message 2");
        $logger->flush();

        $this->assertFileExists($this->testLogFile);

        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString("INFO: Test message 1", $contents);
        $this->assertStringContainsString("ERROR: Test message 2", $contents);
    }

    public function testFileHandlerBuffering(): void
    {
        $logger = new Logger("file", "debug", ["path" => $this->testLogFile]);

        // Log 5 messages (less than buffer size of 100)
        for ($i = 1; $i <= 5; $i++) {
            $logger->info("Message $i");
        }

        // Don't flush yet - file might not exist or be empty due to buffering
        // This is intentional for performance

        // Flush to force write
        $logger->flush();

        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertStringContainsString("Message $i", $contents);
        }
    }

    public function testFileHandlerAutoFlush(): void
    {
        $logger = new Logger("file", "debug", ["path" => $this->testLogFile]);

        // Log more than buffer size (100) to trigger auto-flush
        for ($i = 1; $i <= 150; $i++) {
            $logger->info("Message $i");
        }

        // Should auto-flush at 100 and 200, so file should exist
        $this->assertFileExists($this->testLogFile);

        $logger->flush();

        $contents = file_get_contents($this->testLogFile);
        $lines = explode(PHP_EOL, trim($contents));

        $this->assertCount(150, $lines);
    }

    // ========================================
    // Null Handler Tests
    // ========================================

    public function testNullHandler(): void
    {
        $logger = new Logger("null");

        $result = $logger->info("This goes nowhere");

        $this->assertTrue($result["logged"]);
        // Message is formatted but not written anywhere
        $this->assertStringContainsString("INFO:", $result["formatted"]);
    }

    // ========================================
    // Batch Processing Tests
    // ========================================

    public function testBatchLogging(): void
    {
        $logger = new Logger("null");

        $inputs = [
            ["level" => "info", "message" => "Message 1"],
            ["level" => "warning", "message" => "Message 2"],
            ["level" => "error", "message" => "Message 3"],
        ];

        $results = $logger->logBatch($inputs);

        $this->assertCount(3, $results);

        foreach ($results as $index => $result) {
            $this->assertTrue($result["logged"]);
            $this->assertStringContainsString(
                "Message " . ($index + 1),
                $result["formatted"],
            );
        }
    }

    public function testBatchLoggingWithFiltering(): void
    {
        $logger = new Logger("null", "warning");

        $inputs = [
            ["level" => "debug", "message" => "Debug"],
            ["level" => "info", "message" => "Info"],
            ["level" => "warning", "message" => "Warning"],
            ["level" => "error", "message" => "Error"],
        ];

        $results = $logger->logBatch($inputs);

        $this->assertFalse($results[0]["logged"]); // Debug filtered
        $this->assertFalse($results[1]["logged"]); // Info filtered
        $this->assertTrue($results[2]["logged"]); // Warning logged
        $this->assertTrue($results[3]["logged"]); // Error logged
    }

    // ========================================
    // Format Tests
    // ========================================

    public function testTimestampFormat(): void
    {
        $logger = new Logger("null");

        $result = $logger->info("Test");

        // Check timestamp format: [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression(
            "/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/",
            $result["formatted"],
        );
    }

    public function testMessageFormat(): void
    {
        $logger = new Logger("null");

        $result = $logger->warning("API call failed");

        $expected = "WARNING: API call failed";
        $this->assertStringContainsString($expected, $result["formatted"]);
    }

    public function testCompleteFormat(): void
    {
        $logger = new Logger("null");

        $result = $logger->error("Database timeout", [
            "host" => "db.example.com",
            "timeout" => 30,
        ]);

        // Format: [date] LEVEL: message | key: value, key: value
        $pattern =
            "/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] ERROR: Database timeout \| host: db\.example\.com, timeout: 30/";

        $this->assertMatchesRegularExpression($pattern, $result["formatted"]);
    }

    // ========================================
    // Edge Cases Tests
    // ========================================

    public function testEmptyMessage(): void
    {
        $logger = new Logger("null");

        $result = $logger->info("");

        $this->assertTrue($result["logged"]);
        $this->assertStringContainsString("INFO:", $result["formatted"]);
    }

    public function testSpecialCharactersInMessage(): void
    {
        $logger = new Logger("null");

        $message = "Error: <script>alert('XSS')</script>";
        $result = $logger->error($message);

        $this->assertStringContainsString($message, $result["formatted"]);
    }

    public function testUnicodeInMessage(): void
    {
        $logger = new Logger("null");

        $result = $logger->info("ユーザーログイン成功");

        $this->assertStringContainsString(
            "ユーザーログイン成功",
            $result["formatted"],
        );
    }

    public function testMultilineMessage(): void
    {
        $logger = new Logger("null");

        $message = "Line 1\nLine 2\nLine 3";
        $result = $logger->error($message);

        $this->assertStringContainsString($message, $result["formatted"]);
    }

    public function testInvalidLevel(): void
    {
        $logger = new Logger("null");

        // Invalid level should default to INFO
        $result = $logger->log([
            "level" => "invalid",
            "message" => "Test",
        ]);

        $this->assertTrue($result["logged"]);
        $this->assertStringContainsString("INFO:", $result["formatted"]);
    }

    public function testMissingInputFields(): void
    {
        $logger = new Logger("null");

        // Missing level and message
        $result = $logger->log([]);

        $this->assertTrue($result["logged"]);
        $this->assertStringContainsString("INFO:", $result["formatted"]);
    }

    // ========================================
    // Performance Tests
    // ========================================

    public function testPerformanceBenchmark(): void
    {
        $logger = new Logger("null");

        $iterations = 10000;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $logger->info("Benchmark message", [
                "iteration" => $i,
                "user_id" => 12345,
                "action" => "page_view",
            ]);
        }

        $elapsed = (microtime(true) - $start) * 1000; // Convert to ms

        // Should complete in under 100ms
        $this->assertLessThan(
            100,
            $elapsed,
            "Performance: {$iterations} logs took {$elapsed}ms (expected <100ms)",
        );

        echo "\n✓ Performance: {$iterations} logs in " .
            round($elapsed, 1) .
            "ms";
    }

    public function testFileHandlerPerformance(): void
    {
        $logger = new Logger("file", "debug", ["path" => $this->testLogFile]);

        $iterations = 10000;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $logger->info("Performance test", ["iteration" => $i]);
        }

        $logger->flush();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertLessThan(
            150,
            $elapsed,
            "File handler: {$iterations} logs took {$elapsed}ms (expected <150ms)",
        );

        echo "\n✓ File handler: {$iterations} logs in " .
            round($elapsed, 1) .
            "ms";
    }

    public function testMemoryUsage(): void
    {
        $logger = new Logger("null");

        $initialMemory = memory_get_usage();

        // Log 10,000 messages
        for ($i = 0; $i < 10000; $i++) {
            $logger->info("Memory test", ["iteration" => $i]);
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // MB

        // Memory increase should be minimal (< 5MB for null handler)
        $this->assertLessThan(
            5,
            $memoryIncrease,
            "Memory increased by {$memoryIncrease}MB (expected <5MB)",
        );

        echo "\n✓ Memory: {$memoryIncrease}MB increase for 10,000 logs";
    }

    // ========================================
    // Concurrent Usage Tests
    // ========================================

    public function testMultipleLoggers(): void
    {
        $logger1 = new Logger("null", "debug");
        $logger2 = new Logger("null", "error");

        $result1 = $logger1->debug("Debug from logger 1");
        $result2 = $logger2->debug("Debug from logger 2");

        $this->assertTrue($result1["logged"]);
        $this->assertFalse($result2["logged"]); // Filtered by logger 2
    }

    public function testLoggerReusability(): void
    {
        $logger = new Logger("null");

        // Log messages over multiple calls
        for ($i = 0; $i < 100; $i++) {
            $result = $logger->info("Message $i");
            $this->assertTrue($result["logged"]);
        }

        // Logger should still work after many calls
        $final = $logger->info("Final message");
        $this->assertTrue($final["logged"]);
        $this->assertStringContainsString("Final message", $final["formatted"]);
    }

    // ========================================
    // Real-World Scenario Tests
    // ========================================

    public function testWebRequestLogging(): void
    {
        $logger = new Logger("null");

        $result = $logger->info("HTTP request", [
            "method" => "POST",
            "path" => "/api/users",
            "status" => 201,
            "duration_ms" => 45,
            "ip" => "192.168.1.100",
        ]);

        $formatted = $result["formatted"];

        $this->assertStringContainsString("method: POST", $formatted);
        $this->assertStringContainsString("path: /api/users", $formatted);
        $this->assertStringContainsString("status: 201", $formatted);
        $this->assertStringContainsString("duration_ms: 45", $formatted);
    }

    public function testErrorTracking(): void
    {
        $logger = new Logger("null");

        $result = $logger->error("Unhandled exception", [
            "exception" => "RuntimeException",
            "message" => "Database connection failed",
            "file" => "/app/Database.php",
            "line" => 127,
            "trace" => ["DB::connect", "App::init"],
        ]);

        $formatted = $result["formatted"];

        $this->assertStringContainsString(
            "exception: RuntimeException",
            $formatted,
        );
        $this->assertStringContainsString(
            "file: /app/Database.php",
            $formatted,
        );
        $this->assertStringContainsString("line: 127", $formatted);
    }

    public function testApplicationMonitoring(): void
    {
        $logger = new Logger("null");

        $metrics = [
            "cpu_usage" => 45.2,
            "memory_mb" => 128,
            "active_connections" => 42,
            "queue_size" => 1500,
        ];

        $result = $logger->info("System metrics", $metrics);

        $this->assertTrue($result["logged"]);
        foreach ($metrics as $key => $value) {
            $this->assertStringContainsString(
                "{$key}: {$value}",
                $result["formatted"],
            );
        }
    }
}
