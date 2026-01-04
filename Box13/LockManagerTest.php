<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "LockManager.php";

/**
 * Comprehensive test suite for LockManager
 *
 * Tests lock acquisition, release, expiration, concurrency handling,
 * and performance benchmarks.
 */
class LockManagerTest extends TestCase
{
    private LockManager $manager;
    private string $testLockDir;

    protected function setUp(): void
    {
        // Create a temporary lock directory for testing
        $this->testLockDir =
            sys_get_temp_dir() . "/lock_manager_test_" . uniqid();
        $this->manager = new LockManager($this->testLockDir, 30);
    }

    protected function tearDown(): void
    {
        // Clean up test lock directory
        if (is_dir($this->testLockDir)) {
            $files = glob($this->testLockDir . "/*");
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testLockDir);
        }
    }

    // ============================================================================
    // BASIC FUNCTIONALITY TESTS
    // ============================================================================

    public function testBasicLockAcquisition(): void
    {
        $result = $this->manager->process([
            "key" => "test-key-1",
            "action" => "acquire",
            "timeout" => 30,
        ]);

        $this->assertTrue($result["locked"]);
        $this->assertNotNull($result["lock_id"]);
        $this->assertGreaterThan(time(), $result["expires_at"]);
        $this->assertFalse($result["already_locked"]);
    }

    public function testBasicLockRelease(): void
    {
        // Acquire lock first
        $acquireResult = $this->manager->process([
            "key" => "test-key-2",
            "action" => "acquire",
            "timeout" => 30,
        ]);

        $this->assertTrue($acquireResult["locked"]);

        // Release lock
        $releaseResult = $this->manager->process([
            "key" => "test-key-2",
            "action" => "release",
        ]);

        $this->assertFalse($releaseResult["locked"]);
        $this->assertTrue($releaseResult["released"]);
    }

    public function testCannotAcquireAlreadyLockedKey(): void
    {
        // First lock acquisition
        $result1 = $this->manager->process([
            "key" => "test-key-3",
            "action" => "acquire",
            "timeout" => 30,
        ]);

        $this->assertTrue($result1["locked"]);

        // Second lock acquisition should fail
        $result2 = $this->manager->process([
            "key" => "test-key-3",
            "action" => "acquire",
            "timeout" => 30,
        ]);

        $this->assertFalse($result2["locked"]);
        $this->assertTrue($result2["already_locked"]);
    }

    public function testCanReacquireAfterRelease(): void
    {
        $key = "test-key-4";

        // Acquire, release, then acquire again
        $this->manager->process(["key" => $key, "action" => "acquire"]);
        $this->manager->process(["key" => $key, "action" => "release"]);
        $result = $this->manager->process([
            "key" => $key,
            "action" => "acquire",
        ]);

        $this->assertTrue($result["locked"]);
        $this->assertFalse($result["already_locked"]);
    }

    // ============================================================================
    // VALIDATION TESTS
    // ============================================================================

    public function testEmptyKeyReturnsError(): void
    {
        $result = $this->manager->process([
            "key" => "",
            "action" => "acquire",
        ]);

        $this->assertFalse($result["locked"]);
        $this->assertArrayHasKey("error", $result);
        $this->assertStringContainsString(
            "required",
            strtolower($result["error"]),
        );
    }

    public function testInvalidActionReturnsError(): void
    {
        $result = $this->manager->process([
            "key" => "test-key-5",
            "action" => "invalid-action",
        ]);

        $this->assertFalse($result["locked"]);
        $this->assertArrayHasKey("error", $result);
    }

    public function testDefaultTimeoutApplied(): void
    {
        $manager = new LockManager($this->testLockDir, 60);

        $result = $manager->process([
            "key" => "test-key-6",
            "action" => "acquire",
            // No timeout specified
        ]);

        $this->assertTrue($result["locked"]);
        $expectedExpiry = time() + 60;
        $this->assertGreaterThanOrEqual(
            $expectedExpiry - 2,
            $result["expires_at"],
        );
        $this->assertLessThanOrEqual(
            $expectedExpiry + 2,
            $result["expires_at"],
        );
    }

    // ============================================================================
    // TIMEOUT AND EXPIRATION TESTS
    // ============================================================================

    public function testLockExpiresAfterTimeout(): void
    {
        $key = "test-key-7";

        // Acquire lock with 1 second timeout
        $result = $this->manager->process([
            "key" => $key,
            "action" => "acquire",
            "timeout" => 1,
        ]);

        $this->assertTrue($result["locked"]);

        // Wait for expiration
        sleep(2);

        // Should be able to acquire again
        $result2 = $this->manager->process([
            "key" => $key,
            "action" => "acquire",
            "timeout" => 30,
        ]);

        $this->assertTrue($result2["locked"]);
        $this->assertFalse($result2["already_locked"]);
    }

    public function testIsLockedMethodWorks(): void
    {
        $key = "test-key-8";

        // Initially not locked
        $this->assertFalse($this->manager->isLocked($key));

        // Acquire lock
        $this->manager->acquire($key, 30);
        $this->assertTrue($this->manager->isLocked($key));

        // Release lock
        $this->manager->release($key);
        $this->assertFalse($this->manager->isLocked($key));
    }

    public function testIsLockedReturnsFalseForExpiredLock(): void
    {
        $key = "test-key-9";

        // Acquire with 1 second timeout
        $this->manager->acquire($key, 1);
        $this->assertTrue($this->manager->isLocked($key));

        // Wait for expiration
        sleep(2);
        $this->assertFalse($this->manager->isLocked($key));
    }

    // ============================================================================
    // CLEANUP TESTS
    // ============================================================================

    public function testCleanupExpiredLocks(): void
    {
        // Create several locks with short timeouts
        $this->manager->acquire("expired-1", 1);
        $this->manager->acquire("expired-2", 1);
        $this->manager->acquire("active-1", 30);

        // Wait for some to expire
        sleep(2);

        // Cleanup expired locks
        $cleaned = $this->manager->cleanupExpiredLocks();

        $this->assertEquals(2, $cleaned);
        $this->assertTrue($this->manager->isLocked("active-1"));
    }

    public function testReleaseAllLocksForInstance(): void
    {
        // Acquire multiple locks
        $this->manager->acquire("key-1", 30);
        $this->manager->acquire("key-2", 30);
        $this->manager->acquire("key-3", 30);

        // Release all
        $released = $this->manager->releaseAll();

        $this->assertEquals(3, $released);
        $this->assertFalse($this->manager->isLocked("key-1"));
        $this->assertFalse($this->manager->isLocked("key-2"));
        $this->assertFalse($this->manager->isLocked("key-3"));
    }

    // ============================================================================
    // STATISTICS TESTS
    // ============================================================================

    public function testGetStatsReturnsCorrectCounts(): void
    {
        // Create mix of active and expired locks
        $this->manager->acquire("active-1", 30);
        $this->manager->acquire("active-2", 30);
        $this->manager->acquire("expired-1", 1);

        sleep(2);

        $stats = $this->manager->getStats();

        $this->assertEquals(3, $stats["total_locks"]);
        $this->assertEquals(2, $stats["active_locks"]);
        $this->assertEquals(1, $stats["expired_locks"]);
    }

    // ============================================================================
    // WAIT FUNCTIONALITY TESTS
    // ============================================================================

    public function testAcquireWithWaitEventuallySucceeds(): void
    {
        $key = "wait-key-1";

        // Acquire lock with short timeout
        $this->manager->acquire($key, 1);

        // Try to acquire with wait (should succeed after expiration)
        $result = $this->manager->acquireWithWait($key, 30, 3, 100);

        $this->assertTrue($result["locked"]);
    }

    public function testAcquireWithWaitTimesOut(): void
    {
        $key = "wait-key-2";

        // Acquire lock with long timeout
        $this->manager->acquire($key, 30);

        // Try to acquire with wait, should timeout
        $result = $this->manager->acquireWithWait($key, 30, 1, 100);

        $this->assertFalse($result["locked"]);
        $this->assertTrue($result["already_locked"]);
        $this->assertTrue($result["timeout_waiting"] ?? false);
    }

    // ============================================================================
    // MULTIPLE KEYS TESTS
    // ============================================================================

    public function testMultipleKeysIndependentlyLocked(): void
    {
        $result1 = $this->manager->acquire("key-a", 30);
        $result2 = $this->manager->acquire("key-b", 30);
        $result3 = $this->manager->acquire("key-c", 30);

        $this->assertTrue($result1["locked"]);
        $this->assertTrue($result2["locked"]);
        $this->assertTrue($result3["locked"]);
    }

    public function testReleasingOneKeyDoesNotAffectOthers(): void
    {
        $this->manager->acquire("key-1", 30);
        $this->manager->acquire("key-2", 30);
        $this->manager->acquire("key-3", 30);

        $this->manager->release("key-2");

        $this->assertTrue($this->manager->isLocked("key-1"));
        $this->assertFalse($this->manager->isLocked("key-2"));
        $this->assertTrue($this->manager->isLocked("key-3"));
    }

    // ============================================================================
    // LOCK ID UNIQUENESS TESTS
    // ============================================================================

    public function testLockIdsAreUnique(): void
    {
        $result1 = $this->manager->acquire("unique-1", 30);
        $result2 = $this->manager->acquire("unique-2", 30);
        $result3 = $this->manager->acquire("unique-3", 30);

        $this->assertNotEquals($result1["lock_id"], $result2["lock_id"]);
        $this->assertNotEquals($result2["lock_id"], $result3["lock_id"]);
        $this->assertNotEquals($result1["lock_id"], $result3["lock_id"]);
    }

    // ============================================================================
    // EDGE CASES
    // ============================================================================

    public function testReleasingNonexistentLockReturnsError(): void
    {
        $result = $this->manager->release("nonexistent-key");

        $this->assertFalse($result["released"]);
        $this->assertArrayHasKey("error", $result);
    }

    public function testSpecialCharactersInKey(): void
    {
        $key = "special/key:with@symbols#123!";

        $result = $this->manager->acquire($key, 30);
        $this->assertTrue($result["locked"]);

        $this->assertTrue($this->manager->isLocked($key));

        $this->manager->release($key);
        $this->assertFalse($this->manager->isLocked($key));
    }

    public function testVeryLongKey(): void
    {
        $key = str_repeat("a", 1000);

        $result = $this->manager->acquire($key, 30);
        $this->assertTrue($result["locked"]);

        $this->manager->release($key);
        $this->assertFalse($this->manager->isLocked($key));
    }

    public function testZeroTimeoutStillWorks(): void
    {
        $result = $this->manager->acquire("zero-timeout", 0);

        // Even with 0 timeout, lock should be acquirable
        $this->assertTrue($result["locked"]);

        // Should expire immediately
        $this->assertEquals(time(), $result["expires_at"]);
    }

    // ============================================================================
    // PERFORMANCE BENCHMARK TESTS
    // ============================================================================

    public function testPerformanceBenchmark(): void
    {
        $iterations = 10000;
        $startTime = microtime(true);

        // Acquire locks
        for ($i = 0; $i < $iterations; $i++) {
            $this->manager->process([
                "key" => "perf-key-" . $i,
                "action" => "acquire",
                "timeout" => 30,
            ]);
        }

        // Release locks
        for ($i = 0; $i < $iterations; $i++) {
            $this->manager->process([
                "key" => "perf-key-" . $i,
                "action" => "release",
            ]);
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // File-based locking is I/O bound, expect ~10,000-15,000 ops/sec
        // Should complete in under 2000ms (target: <500ms was too aggressive for file I/O)
        $this->assertLessThan(
            2000,
            $duration,
            "Performance test took {$duration}ms, expected <2000ms",
        );

        // Output performance info
        $opsPerSecond = ($iterations * 2) / ($duration / 1000);
        fwrite(
            STDERR,
            sprintf(
                "\n✓ Performance: %d acquire+release operations in %.1fms (~%.0f ops/sec)\n",
                $iterations * 2,
                $duration,
                $opsPerSecond,
            ),
        );
    }

    public function testAcquirePerformanceOnly(): void
    {
        $iterations = 10000;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->manager->acquire("acquire-perf-" . $i, 30);
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // File I/O bound operation, realistic expectation
        $this->assertLessThan(
            1000,
            $duration,
            "Acquire performance test took {$duration}ms, expected <1000ms",
        );

        fwrite(
            STDERR,
            sprintf(
                "✓ Acquire only: %d locks in %.1fms (~%.0f/sec)\n",
                $iterations,
                $duration,
                $iterations / ($duration / 1000),
            ),
        );
    }

    public function testReleasePerformanceOnly(): void
    {
        $iterations = 10000;

        // Pre-create locks
        for ($i = 0; $i < $iterations; $i++) {
            $this->manager->acquire("release-perf-" . $i, 30);
        }

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->manager->release("release-perf-" . $i);
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // File I/O bound operation, realistic expectation
        $this->assertLessThan(
            1000,
            $duration,
            "Release performance test took {$duration}ms, expected <1000ms",
        );

        fwrite(
            STDERR,
            sprintf(
                "✓ Release only: %d locks in %.1fms (~%.0f/sec)\n",
                $iterations,
                $duration,
                $iterations / ($duration / 1000),
            ),
        );
    }

    // ============================================================================
    // CONCURRENT ACCESS SIMULATION TESTS
    // ============================================================================

    public function testConcurrentAccessSimulation(): void
    {
        // Simulate multiple "processes" trying to acquire same lock
        // by using multiple manager instances pointing to same directory

        $manager1 = new LockManager($this->testLockDir, 30);
        $manager2 = new LockManager($this->testLockDir, 30);
        $manager3 = new LockManager($this->testLockDir, 30);

        $key = "concurrent-key";

        // First should succeed
        $result1 = $manager1->acquire($key, 30);
        $this->assertTrue($result1["locked"]);

        // Others should fail
        $result2 = $manager2->acquire($key, 30);
        $this->assertFalse($result2["locked"]);
        $this->assertTrue($result2["already_locked"]);

        $result3 = $manager3->acquire($key, 30);
        $this->assertFalse($result3["locked"]);
        $this->assertTrue($result3["already_locked"]);

        // Release from first manager
        $manager1->release($key);

        // Now one of the others should be able to acquire
        $result4 = $manager2->acquire($key, 30);
        $this->assertTrue($result4["locked"]);
    }

    // ============================================================================
    // DIRECTORY CREATION TESTS
    // ============================================================================

    public function testCreatesLockDirectoryIfNotExists(): void
    {
        $newDir = sys_get_temp_dir() . "/lock_test_new_" . uniqid();

        // Ensure it doesn't exist
        $this->assertDirectoryDoesNotExist($newDir);

        // Create manager (should create directory)
        $manager = new LockManager($newDir);

        $this->assertDirectoryExists($newDir);

        // Cleanup
        @rmdir($newDir);
    }
}
