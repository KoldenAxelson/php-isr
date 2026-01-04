<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/HealthMonitor.php";

/**
 * Tests for HealthMonitor
 *
 * Verifies health check accuracy, performance, and reliability
 */
class HealthMonitorTest extends TestCase
{
    private HealthMonitor $monitor;
    private string $testDir;

    protected function setUp(): void
    {
        $this->monitor = new HealthMonitor();
        $this->testDir =
            sys_get_temp_dir() . "/health_monitor_test_" . uniqid();

        // Create test directory
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->testDir)) {
            $this->recursiveRemoveDirectory($this->testDir);
        }
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), [".", ".."]);
        foreach ($files as $file) {
            $path = $dir . "/" . $file;
            is_dir($path)
                ? $this->recursiveRemoveDirectory($path)
                : unlink($path);
        }
        rmdir($dir);
    }

    // ===== Basic Health Check Tests =====

    public function testBasicHealthCheck(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["permissions"],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey("healthy", $result);
        $this->assertArrayHasKey("checks", $result);
        $this->assertIsBool($result["healthy"]);
    }

    public function testHealthySystemReturnsTrue(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["permissions", "php_version"],
        ]);

        $this->assertTrue($result["healthy"]);
        $this->assertEquals("ok", $result["checks"]["permissions"]["status"]);
        $this->assertEquals("ok", $result["checks"]["php_version"]["status"]);
    }

    public function testAllChecksRunByDefault(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
        ]);

        $checks = $result["checks"];
        $this->assertArrayHasKey("disk_space", $checks);
        $this->assertArrayHasKey("permissions", $checks);
        $this->assertArrayHasKey("php_version", $checks);
        $this->assertArrayHasKey("memory", $checks);
    }

    // ===== Permissions Check Tests =====

    public function testPermissionsCheckOnWritableDirectory(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["permissions"],
        ]);

        $check = $result["checks"]["permissions"];
        $this->assertEquals("ok", $check["status"]);
        $this->assertTrue($check["writable"]);
        $this->assertTrue($check["exists"]);
    }

    public function testPermissionsCheckOnNonExistentDirectory(): void
    {
        $nonExistent = $this->testDir . "/subdir/does_not_exist";

        $result = $this->monitor->check([
            "cache_dir" => $nonExistent,
            "checks" => ["permissions"],
        ]);

        $check = $result["checks"]["permissions"];
        $this->assertEquals("ok", $check["status"]);
        $this->assertTrue($check["writable"]);
        $this->assertFalse($check["exists"]);
    }

    public function testPermissionsCheckOnReadOnlyDirectory(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
            $this->markTestSkipped(
                "Read-only directory test skipped on Windows",
            );
        }

        $readOnlyDir = $this->testDir . "/readonly";
        mkdir($readOnlyDir, 0444);

        $result = $this->monitor->check([
            "cache_dir" => $readOnlyDir,
            "checks" => ["permissions"],
        ]);

        $check = $result["checks"]["permissions"];
        $this->assertEquals("fail", $check["status"]);
        $this->assertFalse($check["writable"]);

        // Restore permissions for cleanup
        chmod($readOnlyDir, 0755);
    }

    public function testPermissionsCheckWithEmptyPath(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => "",
            "checks" => ["permissions"],
        ]);

        $check = $result["checks"]["permissions"];
        $this->assertEquals("fail", $check["status"]);
        $this->assertArrayHasKey("error", $check);
    }

    // ===== Disk Space Check Tests =====

    public function testDiskSpaceCheckReturnsBytes(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["disk_space"],
        ]);

        $check = $result["checks"]["disk_space"];
        $this->assertArrayHasKey("available", $check);
        $this->assertArrayHasKey("available_bytes", $check);
        $this->assertIsInt($check["available_bytes"]);
        $this->assertGreaterThan(0, $check["available_bytes"]);
    }

    public function testDiskSpaceCheckFormatsBytes(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["disk_space"],
        ]);

        $check = $result["checks"]["disk_space"];
        $formatted = $check["available"];

        // Should contain a unit (GB, MB, KB, etc.)
        $this->assertMatchesRegularExpression(
            "/\d+(\.\d+)?(GB|MB|KB|B)/",
            $formatted,
        );
    }

    public function testDiskSpaceCheckWithMinimumRequirement(): void
    {
        // Set very low minimum (1KB) to ensure check passes
        $monitor = new HealthMonitor(["min_disk_space" => 1024]);

        $result = $monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["disk_space"],
        ]);

        $check = $result["checks"]["disk_space"];
        $this->assertEquals("ok", $check["status"]);
        $this->assertEquals(1024, $check["minimum_required_bytes"]);
    }

    public function testDiskSpaceCheckFailsWithInsufficientSpace(): void
    {
        // Set impossibly high minimum to force failure
        $monitor = new HealthMonitor(["min_disk_space" => PHP_INT_MAX]);

        $result = $monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["disk_space"],
        ]);

        $check = $result["checks"]["disk_space"];
        $this->assertEquals("fail", $check["status"]);
    }

    public function testDiskSpaceCheckOnNonExistentPath(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => "/path/that/does/not/exist/anywhere",
            "checks" => ["disk_space"],
        ]);

        $check = $result["checks"]["disk_space"];
        $this->assertEquals("fail", $check["status"]);
        $this->assertArrayHasKey("error", $check);
    }

    // ===== PHP Version Check Tests =====

    public function testPhpVersionCheckReturnsCurrentVersion(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["php_version"],
        ]);

        $check = $result["checks"]["php_version"];
        $this->assertEquals("ok", $check["status"]);
        $this->assertEquals(PHP_VERSION, $check["version"]);
    }

    public function testPhpVersionCheckWithCustomMinimum(): void
    {
        $monitor = new HealthMonitor(["min_php_version" => "7.0.0"]);

        $result = $monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["php_version"],
        ]);

        $check = $result["checks"]["php_version"];
        $this->assertEquals("ok", $check["status"]);
        $this->assertEquals("7.0.0", $check["minimum_required"]);
    }

    public function testPhpVersionCheckFailsWithHighMinimum(): void
    {
        $monitor = new HealthMonitor(["min_php_version" => "99.0.0"]);

        $result = $monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["php_version"],
        ]);

        $check = $result["checks"]["php_version"];
        $this->assertEquals("fail", $check["status"]);
        $this->assertArrayHasKey("error", $check);
    }

    // ===== Memory Check Tests =====

    public function testMemoryCheckReturnsUsageInfo(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["memory"],
        ]);

        $check = $result["checks"]["memory"];
        $this->assertArrayHasKey("available", $check);
        $this->assertArrayHasKey("usage", $check);
        $this->assertArrayHasKey("usage_bytes", $check);
    }

    public function testMemoryCheckWithLowMinimum(): void
    {
        // Set very low minimum (1KB) to ensure check passes
        $monitor = new HealthMonitor(["min_memory" => 1024]);

        $result = $monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["memory"],
        ]);

        $check = $result["checks"]["memory"];
        $this->assertEquals("ok", $check["status"]);
    }

    public function testMemoryCheckFormatsBytes(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["memory"],
        ]);

        $check = $result["checks"]["memory"];

        // Available should be formatted (unless unlimited)
        if ($check["available"] !== "unlimited") {
            $this->assertMatchesRegularExpression(
                "/\d+(\.\d+)?(GB|MB|KB|B)/",
                $check["available"],
            );
        }

        // Usage should always be formatted
        $this->assertMatchesRegularExpression(
            "/\d+(\.\d+)?(GB|MB|KB|B)/",
            $check["usage"],
        );
    }

    // ===== Overall Health Tests =====

    public function testUnhealthyWhenAnyCheckFails(): void
    {
        // Force disk space check to fail
        $monitor = new HealthMonitor(["min_disk_space" => PHP_INT_MAX]);

        $result = $monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["disk_space", "permissions"],
        ]);

        $this->assertFalse($result["healthy"]);
        $this->assertEquals("fail", $result["checks"]["disk_space"]["status"]);
    }

    public function testHealthyWhenAllCheckPass(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["permissions", "php_version"],
        ]);

        $this->assertTrue($result["healthy"]);
    }

    // ===== Unknown Check Tests =====

    public function testUnknownCheckReturnsFailure(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["unknown_check"],
        ]);

        $check = $result["checks"]["unknown_check"];
        $this->assertEquals("fail", $check["status"]);
        $this->assertStringContainsString("Unknown check", $check["error"]);
    }

    // ===== Batch Processing Tests =====

    public function testBatchCheckProcessesMultipleInputs(): void
    {
        $inputs = [
            ["cache_dir" => $this->testDir, "checks" => ["permissions"]],
            ["cache_dir" => $this->testDir, "checks" => ["php_version"]],
            ["cache_dir" => $this->testDir, "checks" => ["disk_space"]],
        ];

        $results = $this->monitor->checkBatch($inputs);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey(0, $results);
        $this->assertArrayHasKey(1, $results);
        $this->assertArrayHasKey(2, $results);
    }

    public function testBatchCheckPreservesIndices(): void
    {
        $inputs = [
            "first" => [
                "cache_dir" => $this->testDir,
                "checks" => ["permissions"],
            ],
            "second" => [
                "cache_dir" => $this->testDir,
                "checks" => ["php_version"],
            ],
        ];

        $results = $this->monitor->checkBatch($inputs);

        $this->assertArrayHasKey("first", $results);
        $this->assertArrayHasKey("second", $results);
    }

    // ===== Quick Health Check Tests =====

    public function testIsHealthyReturnsBoolean(): void
    {
        $isHealthy = $this->monitor->isHealthy($this->testDir);
        $this->assertIsBool($isHealthy);
    }

    public function testIsHealthyReturnsTrueForHealthySystem(): void
    {
        $isHealthy = $this->monitor->isHealthy($this->testDir);
        $this->assertTrue($isHealthy);
    }

    // ===== Configuration Tests =====

    public function testCustomConfiguration(): void
    {
        $config = [
            "min_disk_space" => 2048,
            "min_php_version" => "7.0.0",
            "min_memory" => 1024,
        ];

        $monitor = new HealthMonitor($config);

        $result = $monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["disk_space", "php_version", "memory"],
        ]);

        $this->assertEquals(
            2048,
            $result["checks"]["disk_space"]["minimum_required_bytes"],
        );
        $this->assertEquals(
            "7.0.0",
            $result["checks"]["php_version"]["minimum_required"],
        );
        $this->assertEquals(
            1024,
            $result["checks"]["memory"]["minimum_required_bytes"],
        );
    }

    // ===== Edge Cases =====

    public function testEmptyChecksArrayRunsAllChecks(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => [],
        ]);

        // Empty checks should default to all checks
        $this->assertEmpty($result["checks"]);
        $this->assertTrue($result["healthy"]); // No checks = healthy
    }

    public function testMissingCacheDirUsesEmptyString(): void
    {
        $result = $this->monitor->check([
            "checks" => ["permissions"],
        ]);

        $check = $result["checks"]["permissions"];
        $this->assertEquals("fail", $check["status"]);
    }

    // ===== Performance Tests =====

    public function testPerformanceSingleCheck(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            $this->monitor->check([
                "cache_dir" => $this->testDir,
                "checks" => ["permissions"],
            ]);
        }

        $duration = (microtime(true) - $start) * 1000;

        // Should complete 1,000 permission checks in under 200ms
        // (threshold accounts for varying test environments - Mac, Linux, CI)
        $this->assertLessThan(
            200,
            $duration,
            "1,000 permission checks took {$duration}ms (expected <200ms)",
        );
    }

    public function testPerformanceAllChecks(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < 100; $i++) {
            $this->monitor->check([
                "cache_dir" => $this->testDir,
            ]);
        }

        $duration = (microtime(true) - $start) * 1000;

        // Should complete 100 full health checks (all 4 checks) in under 50ms
        $this->assertLessThan(
            50,
            $duration,
            "100 full health checks took {$duration}ms (expected <50ms)",
        );

        // Report performance
        $avgDuration = $duration / 100;
        echo "\n✓ Performance: 100 full health checks in " .
            round($duration, 2) .
            "ms";
        echo " (~" . round($avgDuration, 2) . "ms per check)\n";
    }

    public function testPerformanceBatchProcessing(): void
    {
        $inputs = array_fill(0, 100, [
            "cache_dir" => $this->testDir,
            "checks" => ["permissions", "php_version"],
        ]);

        $start = microtime(true);
        $this->monitor->checkBatch($inputs);
        $duration = (microtime(true) - $start) * 1000;

        // Batch processing 100 items should be under 50ms
        $this->assertLessThan(
            50,
            $duration,
            "Batch processing 100 checks took {$duration}ms (expected <50ms)",
        );
    }

    // ===== Accuracy Tests =====

    public function testDiskSpaceAccuracy(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["disk_space"],
        ]);

        $check = $result["checks"]["disk_space"];
        $reportedBytes = $check["available_bytes"];
        $actualBytes = disk_free_space($this->testDir);

        // Reported and actual should match exactly
        $this->assertEquals($actualBytes, $reportedBytes);
    }

    public function testMemoryAccuracy(): void
    {
        $result = $this->monitor->check([
            "cache_dir" => $this->testDir,
            "checks" => ["memory"],
        ]);

        $check = $result["checks"]["memory"];
        $reportedUsage = $check["usage_bytes"];
        $actualUsage = memory_get_usage(true);

        // Memory usage should be within 10% (accounts for test execution overhead)
        $tolerance = $actualUsage * 0.1;
        $this->assertEqualsWithDelta($actualUsage, $reportedUsage, $tolerance);
    }
}
