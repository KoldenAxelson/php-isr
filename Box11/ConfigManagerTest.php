<?php

declare(strict_types=1);

require_once __DIR__ . "/ConfigManager.php";

use PHPUnit\Framework\TestCase;

/**
 * ConfigManager Test Suite (Hardened Version)
 *
 * Comprehensive tests for configuration management including:
 * - Multiple input sources (array, file, JSON, env)
 * - Type-safe getters
 * - Dot notation support
 * - Defaults and validation
 * - Immutability
 * - Performance benchmarks
 * - Security features (path traversal, sensitive values, etc.)
 */
class ConfigManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temp directory for test files
        $this->tempDir = sys_get_temp_dir() . "/config_test_" . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory recursively
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    /**
     * Recursively delete a directory and all its contents
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = glob($dir . "/*");
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->recursiveDelete($item);
            } else {
                unlink($item);
            }
        }
        rmdir($dir);
    }

    // =========================================================================
    // Basic Construction
    // =========================================================================

    public function testConstructWithEmptyArray(): void
    {
        $config = new ConfigManager([]);

        // Should have defaults
        $this->assertEquals("./cache", $config->getCacheDir());
        $this->assertEquals(3600, $config->getDefaultTTL());
        $this->assertFalse($config->useSharding());
    }

    public function testConstructWithCustomConfig(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/var/cache/isr",
                "default_ttl" => 60,
                "use_sharding" => true,
            ],
        ]);

        $this->assertEquals("/var/cache/isr", $config->getCacheDir());
        $this->assertEquals(60, $config->getDefaultTTL());
        $this->assertTrue($config->useSharding());
    }

    public function testDefaultsAreMerged(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/custom/cache",
            ],
        ]);

        // Custom value
        $this->assertEquals("/custom/cache", $config->getCacheDir());

        // Defaults still present
        $this->assertEquals(3600, $config->getDefaultTTL());
        $this->assertFalse($config->useSharding());
    }

    // =========================================================================
    // Loading from Files
    // =========================================================================

    public function testLoadFromPhpFile(): void
    {
        $configFile = $this->tempDir . "/config.php";
        file_put_contents(
            $configFile,
            '<?php return [
            "cache" => [
                "dir" => "/test/cache",
                "default_ttl" => 120,
            ],
        ];',
        );

        $config = ConfigManager::fromFile($configFile);

        $this->assertEquals("/test/cache", $config->getCacheDir());
        $this->assertEquals(120, $config->getDefaultTTL());
    }

    public function testLoadFromJsonFile(): void
    {
        $configFile = $this->tempDir . "/config.json";
        file_put_contents(
            $configFile,
            json_encode([
                "cache" => [
                    "dir" => "/json/cache",
                    "default_ttl" => 300,
                ],
            ]),
        );

        $config = ConfigManager::fromJson($configFile);

        $this->assertEquals("/json/cache", $config->getCacheDir());
        $this->assertEquals(300, $config->getDefaultTTL());
    }

    public function testLoadFromMissingFileThrowsException(): void
    {
        $this->expectException(ConfigFileException::class);
        $this->expectExceptionMessage("Config file not found");

        ConfigManager::fromFile("/nonexistent/config.php");
    }

    public function testLoadFromInvalidJsonThrowsException(): void
    {
        $configFile = $this->tempDir . "/invalid.json";
        file_put_contents($configFile, "not valid json{");

        $this->expectException(ConfigFileException::class);
        $this->expectExceptionMessage("Invalid JSON");

        ConfigManager::fromJson($configFile);
    }

    public function testLoadFromPhpFileNotReturningArrayThrowsException(): void
    {
        $configFile = $this->tempDir . "/bad.php";
        file_put_contents($configFile, '<?php return "not an array";');

        $this->expectException(ConfigFileException::class);
        $this->expectExceptionMessage("must return an array");

        ConfigManager::fromFile($configFile);
    }

    public function testLoadFromValidJsonNull(): void
    {
        $configFile = $this->tempDir . "/null.json";
        file_put_contents($configFile, "null");

        // Should not throw exception, should treat as empty config
        $config = ConfigManager::fromJson($configFile);

        $this->assertEquals("./cache", $config->getCacheDir()); // Uses defaults
    }

    // =========================================================================
    // Security Tests
    // =========================================================================

    public function testPathTraversalProtectionWithBasePath(): void
    {
        $configFile = $this->tempDir . "/config.php";
        file_put_contents(
            $configFile,
            '<?php return ["cache" => ["dir" => "/test"]];',
        );

        // Should work when within allowed base path
        $config = ConfigManager::fromFile($configFile, $this->tempDir);
        $this->assertEquals("/test", $config->getCacheDir());
    }

    public function testPathTraversalPreventedOutsideBasePath(): void
    {
        // Create config in temp dir
        $configFile = $this->tempDir . "/config.php";
        file_put_contents(
            $configFile,
            '<?php return ["cache" => ["dir" => "/test"]];',
        );

        // Create a different base path
        $restrictedDir = $this->tempDir . "/restricted";
        mkdir($restrictedDir, 0755);

        $this->expectException(ConfigSecurityException::class);
        $this->expectExceptionMessage("outside allowed base path");

        // Try to load config from outside the restricted dir
        ConfigManager::fromFile($configFile, $restrictedDir);
    }

    public function testNullByteInPathRejected(): void
    {
        $this->expectException(ConfigSecurityException::class);
        $this->expectExceptionMessage("contains null byte");

        ConfigManager::fromFile("/tmp/config.php\0.txt");
    }

    public function testSensitiveValueWarnings(): void
    {
        $config = new ConfigManager([
            "database" => [
                "host" => "localhost",
                "password" => "secret123", // Should trigger warning
            ],
            "api" => [
                "api_key" => "abc123", // Should trigger warning
            ],
        ]);

        $result = $config->validate();

        $this->assertTrue($result["valid"]); // No errors
        $this->assertGreaterThan(0, count($result["warnings"])); // But has warnings
        $this->assertStringContainsString("password", $result["warnings"][0]);
    }

    public function testSensitiveValueNoWarningWhenEmpty(): void
    {
        $config = new ConfigManager([
            "database" => [
                "password" => "", // Empty, should not warn
            ],
        ]);

        $result = $config->validate();

        $this->assertCount(0, $result["warnings"]);
    }

    public function testUnreadableFileThrowsException(): void
    {
        $configFile = $this->tempDir . "/unreadable.php";
        file_put_contents(
            $configFile,
            '<?php return ["cache" => ["dir" => "/test"]];',
        );

        // Make file unreadable
        chmod($configFile, 0000);

        $this->expectException(ConfigFileException::class);
        $this->expectExceptionMessage("not readable");

        try {
            ConfigManager::fromFile($configFile);
        } finally {
            // Restore permissions for cleanup
            chmod($configFile, 0644);
        }
    }

    // =========================================================================
    // Environment Variables
    // =========================================================================

    public function testLoadFromEnvironmentVariables(): void
    {
        // Set in both $_ENV and putenv() for compatibility
        $_ENV["ISR_CACHE_DIR"] = "/env/cache";
        $_ENV["ISR_CACHE_DEFAULT_TTL"] = "600";
        $_ENV["ISR_CACHE_USE_SHARDING"] = "true";
        $_ENV["ISR_STATS_ENABLED"] = "false";

        putenv("ISR_CACHE_DIR=/env/cache");
        putenv("ISR_CACHE_DEFAULT_TTL=600");
        putenv("ISR_CACHE_USE_SHARDING=true");
        putenv("ISR_STATS_ENABLED=false");

        $config = ConfigManager::fromEnv("ISR_");

        $this->assertEquals("/env/cache", $config->getCacheDir());
        $this->assertEquals(600, $config->getDefaultTTL());
        $this->assertTrue($config->useSharding());
        $this->assertFalse($config->isStatsEnabled());

        // Clean up
        unset($_ENV["ISR_CACHE_DIR"]);
        unset($_ENV["ISR_CACHE_DEFAULT_TTL"]);
        unset($_ENV["ISR_CACHE_USE_SHARDING"]);
        unset($_ENV["ISR_STATS_ENABLED"]);

        putenv("ISR_CACHE_DIR");
        putenv("ISR_CACHE_DEFAULT_TTL");
        putenv("ISR_CACHE_USE_SHARDING");
        putenv("ISR_STATS_ENABLED");
    }

    public function testEnvVariableTypeCasting(): void
    {
        $_ENV["TEST_INT"] = "42";
        $_ENV["TEST_NEG_INT"] = "-10";
        $_ENV["TEST_BOOL_TRUE"] = "true";
        $_ENV["TEST_BOOL_FALSE"] = "false";
        $_ENV["TEST_FLOAT"] = "3.14";
        $_ENV["TEST_NEG_FLOAT"] = "-2.5";
        $_ENV["TEST_NULL"] = "null";
        $_ENV["TEST_STRING"] = "hello";

        $config = ConfigManager::fromEnv("TEST_");

        $this->assertSame(42, $config->get("int"));
        $this->assertSame(-10, $config->get("neg.int"));
        $this->assertSame(true, $config->get("bool.true"));
        $this->assertSame(false, $config->get("bool.false"));
        $this->assertSame(3.14, $config->get("float"));
        $this->assertSame(-2.5, $config->get("neg.float"));
        $this->assertNull($config->get("null"));
        $this->assertSame("hello", $config->get("string"));

        // Clean up
        foreach (array_keys($_ENV) as $key) {
            if (strpos($key, "TEST_") === 0) {
                unset($_ENV[$key]);
            }
        }
    }

    // =========================================================================
    // Dot Notation Support
    // =========================================================================

    public function testDotNotationAccess(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/test",
                "settings" => [
                    "nested" => "value",
                ],
            ],
        ]);

        $this->assertEquals("/test", $config->get("cache.dir"));
        $this->assertEquals("value", $config->get("cache.settings.nested"));
    }

    public function testDotNotationWithDefault(): void
    {
        $config = new ConfigManager([]);

        $this->assertEquals(
            "default",
            $config->get("nonexistent.key", "default"),
        );
    }

    public function testHasMethod(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/test",
            ],
        ]);

        $this->assertTrue($config->has("cache.dir"));
        $this->assertTrue($config->has("cache"));
        $this->assertFalse($config->has("nonexistent"));
        $this->assertFalse($config->has("cache.nonexistent"));
    }

    // =========================================================================
    // Type-Safe Getters
    // =========================================================================

    public function testGetString(): void
    {
        $config = new ConfigManager([
            "str" => "hello",
            "int" => 42,
        ]);

        $this->assertEquals("hello", $config->getString("str"));
        $this->assertEquals("default", $config->getString("int", "default")); // Wrong type
        $this->assertEquals(
            "default",
            $config->getString("missing", "default"),
        );
    }

    public function testGetInt(): void
    {
        $config = new ConfigManager([
            "int" => 42,
            "str_int" => "100",
            "str" => "not a number",
        ]);

        $this->assertEquals(42, $config->getInt("int"));
        $this->assertEquals(100, $config->getInt("str_int")); // Converts string to int
        $this->assertEquals(99, $config->getInt("str", 99)); // Invalid, uses default
        $this->assertEquals(0, $config->getInt("missing")); // Default is 0
    }

    public function testGetBool(): void
    {
        $config = new ConfigManager([
            "bool_true" => true,
            "bool_false" => false,
            "int_1" => 1,
            "int_0" => 0,
            "str_true" => "true",
            "str_false" => "false",
        ]);

        $this->assertTrue($config->getBool("bool_true"));
        $this->assertFalse($config->getBool("bool_false"));
        $this->assertTrue($config->getBool("int_1"));
        $this->assertFalse($config->getBool("int_0"));
        $this->assertTrue($config->getBool("str_true"));
        $this->assertFalse($config->getBool("str_false"));
    }

    public function testGetFloat(): void
    {
        $config = new ConfigManager([
            "float" => 3.14,
            "int" => 42,
            "str_float" => "2.718",
        ]);

        $this->assertEquals(3.14, $config->getFloat("float"));
        $this->assertEquals(42.0, $config->getFloat("int")); // Converts int to float
        $this->assertEquals(2.718, $config->getFloat("str_float"));
        $this->assertEquals(1.5, $config->getFloat("missing", 1.5));
    }

    public function testGetArray(): void
    {
        $config = new ConfigManager([
            "arr" => [1, 2, 3],
            "not_arr" => "string",
        ]);

        $this->assertEquals([1, 2, 3], $config->getArray("arr"));
        $this->assertEquals([], $config->getArray("not_arr")); // Not array, returns default
        $this->assertEquals(
            ["default"],
            $config->getArray("missing", ["default"]),
        );
    }

    // =========================================================================
    // ISR-Specific Getters
    // =========================================================================

    public function testIsrSpecificGetters(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/custom",
                "default_ttl" => 1800,
                "use_sharding" => true,
            ],
            "freshness" => [
                "stale_window_seconds" => 300,
            ],
            "background" => [
                "timeout" => 60,
            ],
            "stats" => [
                "enabled" => false,
            ],
            "compression" => [
                "enabled" => true,
            ],
        ]);

        $this->assertEquals("/custom", $config->getCacheDir());
        $this->assertEquals(1800, $config->getDefaultTTL());
        $this->assertTrue($config->useSharding());
        $this->assertEquals(300, $config->getStaleWindowSeconds());
        $this->assertEquals(60, $config->getBackgroundTimeout());
        $this->assertFalse($config->isStatsEnabled());
        $this->assertTrue($config->isCompressionEnabled());
    }

    public function testStaleWindowSecondsCanBeNull(): void
    {
        $config = new ConfigManager([
            "freshness" => [
                "stale_window_seconds" => null,
            ],
        ]);

        $this->assertNull($config->getStaleWindowSeconds());
    }

    public function testStaleWindowSecondsHandlesStringNull(): void
    {
        $config = new ConfigManager([
            "freshness" => [
                "stale_window_seconds" => "null",
            ],
        ]);

        // Should handle string "null" gracefully
        $this->assertNull($config->getStaleWindowSeconds());
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function testValidationPasses(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/valid/path",
                "default_ttl" => 3600,
            ],
            "background" => [
                "timeout" => 30,
            ],
            "compression" => [
                "enabled" => true,
                "level" => 5,
            ],
        ]);

        $result = $config->validate();

        $this->assertTrue($result["valid"]);
        $this->assertEmpty($result["errors"]);
    }

    public function testValidationFailsEmptyCacheDir(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "",
            ],
        ]);

        $result = $config->validate();

        $this->assertFalse($result["valid"]);
        $this->assertContains("cache.dir cannot be empty", $result["errors"]);
    }

    public function testValidationFailsNegativeTTL(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "default_ttl" => -100,
            ],
        ]);

        $result = $config->validate();

        $this->assertFalse($result["valid"]);
        $this->assertStringContainsString(
            "cache.default_ttl must be >= 0",
            implode(", ", $result["errors"]),
        );
    }

    public function testValidationFailsInvalidTimeout(): void
    {
        $config = new ConfigManager([
            "background" => [
                "timeout" => 0,
            ],
        ]);

        $result = $config->validate();

        $this->assertFalse($result["valid"]);
        $this->assertStringContainsString(
            "background.timeout must be > 0",
            implode(", ", $result["errors"]),
        );
    }

    public function testValidationFailsInvalidCompressionLevel(): void
    {
        $config = new ConfigManager([
            "compression" => [
                "enabled" => true,
                "level" => 15,
            ],
        ]);

        $result = $config->validate();

        $this->assertFalse($result["valid"]);
        $this->assertStringContainsString(
            "compression.level must be between 1-9",
            implode(", ", $result["errors"]),
        );
    }

    public function testValidationWarnsAboutRelativeCacheDir(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => ".",
            ],
        ]);

        $result = $config->validate();

        $this->assertTrue($result["valid"]); // No errors
        $this->assertGreaterThan(0, count($result["warnings"])); // Has warnings
    }

    // =========================================================================
    // Locking
    // =========================================================================

    public function testLockMakesConfigImmutable(): void
    {
        $config = new ConfigManager([]);

        $this->assertFalse($config->isLocked());

        $config->lock();

        $this->assertTrue($config->isLocked());
    }

    public function testLockReturnsChainableInstance(): void
    {
        $config = new ConfigManager([]);

        $result = $config->lock();

        $this->assertSame($config, $result);
    }

    // =========================================================================
    // Export
    // =========================================================================

    public function testToArray(): void
    {
        $input = [
            "cache" => [
                "dir" => "/test",
                "default_ttl" => 120,
            ],
        ];

        $config = new ConfigManager($input);
        $output = $config->toArray();

        // Should include both input and defaults
        $this->assertEquals("/test", $output["cache"]["dir"]);
        $this->assertEquals(120, $output["cache"]["default_ttl"]);
        $this->assertArrayHasKey("use_sharding", $output["cache"]); // Default present
    }

    public function testToJson(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/test",
            ],
        ]);

        $json = $config->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals("/test", $decoded["cache"]["dir"]);
    }

    public function testToJsonPretty(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/test",
            ],
        ]);

        $json = $config->toJson(true);

        // Pretty JSON should have newlines
        $this->assertStringContainsString("\n", $json);
    }

    public function testAll(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/test",
            ],
        ]);

        $all = $config->all();

        $this->assertIsArray($all);
        $this->assertArrayHasKey("cache", $all);
        $this->assertArrayHasKey("stats", $all); // Defaults included
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testNestedArrayMerge(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/custom",
                // default_ttl not specified, should use default
            ],
            "stats" => [
                "enabled" => false,
                // file not specified, should use default (null)
            ],
        ]);

        $this->assertEquals("/custom", $config->getCacheDir());
        $this->assertEquals(3600, $config->getDefaultTTL()); // Default
        $this->assertFalse($config->isStatsEnabled());
        $this->assertNull($config->get("stats.file")); // Default null
    }

    public function testComplexNestedConfig(): void
    {
        $config = new ConfigManager([
            "custom" => [
                "level1" => [
                    "level2" => [
                        "level3" => "deep value",
                    ],
                ],
            ],
        ]);

        $this->assertEquals(
            "deep value",
            $config->get("custom.level1.level2.level3"),
        );
        $this->assertIsArray($config->get("custom.level1.level2"));
    }

    public function testNonStringKeysInDotNotation(): void
    {
        $config = new ConfigManager([
            "array" => [
                0 => "first",
                1 => "second",
            ],
        ]);

        // Numeric keys work with dot notation
        $this->assertEquals("first", $config->get("array.0"));
        $this->assertEquals("second", $config->get("array.1"));
    }

    // =========================================================================
    // Performance Benchmark
    // =========================================================================

    public function testPerformanceBenchmark(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/bench/cache",
                "default_ttl" => 3600,
            ],
        ]);

        $iterations = 100000;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $config->getCacheDir();
            $config->getDefaultTTL();
            $config->useSharding();
            $config->isStatsEnabled();
        }

        $elapsed = (microtime(true) - $start) * 1000; // Convert to ms

        // Should complete in < 150ms (typical: 20-80ms)
        $this->assertLessThan(
            150,
            $elapsed,
            "Performance: {$iterations} config reads should complete in <150ms (actual: {$elapsed}ms)",
        );

        // Report performance
        $opsPerSecond = number_format($iterations / ($elapsed / 1000));
        echo "\n✓ Performance: {$iterations} config reads in " .
            number_format($elapsed, 2) .
            "ms (~{$opsPerSecond} ops/sec)\n";
    }

    public function testFileLoadingPerformance(): void
    {
        // Create test config file
        $configFile = $this->tempDir . "/perf.php";
        file_put_contents(
            $configFile,
            '<?php return [
            "cache" => ["dir" => "/test", "default_ttl" => 3600],
            "stats" => ["enabled" => true],
        ];',
        );

        $iterations = 1000;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $config = ConfigManager::fromFile($configFile);
            $config->getCacheDir();
        }

        $elapsed = (microtime(true) - $start) * 1000;

        // Should complete in < 50ms (typical: 10-30ms)
        $this->assertLessThan(
            50,
            $elapsed,
            "Performance: {$iterations} file loads should complete in <50ms (actual: {$elapsed}ms)",
        );

        echo "\n✓ Performance: {$iterations} file loads in " .
            number_format($elapsed, 2) .
            "ms\n";
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    public function testRealWorldIsrConfiguration(): void
    {
        $config = new ConfigManager([
            "cache" => [
                "dir" => "/var/www/cache/isr",
                "default_ttl" => 1800,
                "use_sharding" => true,
            ],
            "freshness" => [
                "stale_window_seconds" => 600,
            ],
            "background" => [
                "timeout" => 45,
                "max_retries" => 5,
            ],
            "stats" => [
                "enabled" => true,
                "file" => "/var/log/isr-stats.json",
            ],
            "compression" => [
                "enabled" => true,
                "level" => 6,
            ],
        ]);

        // Validate
        $validation = $config->validate();
        $this->assertTrue($validation["valid"]);

        // Check all ISR settings
        $this->assertEquals("/var/www/cache/isr", $config->getCacheDir());
        $this->assertEquals(1800, $config->getDefaultTTL());
        $this->assertTrue($config->useSharding());
        $this->assertEquals(600, $config->getStaleWindowSeconds());
        $this->assertEquals(45, $config->getBackgroundTimeout());
        $this->assertTrue($config->isStatsEnabled());
        $this->assertTrue($config->isCompressionEnabled());

        // Lock for production use
        $config->lock();
        $this->assertTrue($config->isLocked());
    }
}
