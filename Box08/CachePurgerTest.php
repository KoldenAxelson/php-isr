<?php

declare(strict_types=1);

require_once "CachePurger.php";

use PHPUnit\Framework\TestCase;

/**
 * Test suite for CachePurger
 *
 * Coverage:
 * - Basic purge operations (keys, pattern, purge_all)
 * - Pattern matching (wildcards, edge cases)
 * - Error handling
 * - Performance benchmarks
 * - Edge cases and validation
 */
class CachePurgerTest extends TestCase
{
    private string $testCacheDir;
    private CachePurger $purger;

    protected function setUp(): void
    {
        // Create temporary cache directory
        $this->testCacheDir =
            sys_get_temp_dir() . "/cache_purger_test_" . uniqid();
        mkdir($this->testCacheDir, 0777, true);
        $this->purger = new CachePurger($this->testCacheDir);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->testCacheDir);
    }

    /**
     * Helper: Remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    /**
     * Helper: Create test cache file with metadata
     */
    private function createCacheFile(
        string $key,
        string $content = "test",
        string $url = null,
    ): void {
        $shard = substr($key, 0, 2);
        $dir = $this->testCacheDir . "/" . $shard;

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Create cache file with FileCacheStore format
        $data = [
            "content" => $content,
            "created_at" => time(),
            "ttl" => 3600,
            "metadata" => $url !== null ? ["url" => $url] : [],
        ];

        file_put_contents($dir . "/" . $key . ".cache", json_encode($data));
    }

    /**
     * Helper: Check if cache file exists
     */
    private function cacheFileExists(string $key): bool
    {
        $shard = substr($key, 0, 2);
        $path = $this->testCacheDir . "/" . $shard . "/" . $key . ".cache";
        return file_exists($path);
    }

    // ========================================================================
    // BASIC FUNCTIONALITY TESTS
    // ========================================================================

    public function testPurgeByKeys(): void
    {
        // Create test cache files
        $this->createCacheFile("abc123");
        $this->createCacheFile("def456");
        $this->createCacheFile("ghi789");

        // Purge specific keys
        $result = $this->purger->purge([
            "keys" => ["abc123", "def456"],
        ]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertCount(2, $result["keys_purged"]);
        $this->assertContains("abc123", $result["keys_purged"]);
        $this->assertContains("def456", $result["keys_purged"]);
        $this->assertEmpty($result["errors"]);

        // Verify files deleted
        $this->assertFalse($this->cacheFileExists("abc123"));
        $this->assertFalse($this->cacheFileExists("def456"));
        $this->assertTrue($this->cacheFileExists("ghi789"));
    }

    public function testPurgeByPattern(): void
    {
        // Create test cache files with URLs in metadata
        $this->createCacheFile("abc123", "content", "/blog/post-1");
        $this->createCacheFile("def456", "content", "/blog/post-2");
        $this->createCacheFile("ghi789", "content", "/news/article-1");
        $this->createCacheFile("jkl012", "content", "/product/123");

        // Purge blog posts by URL pattern
        $result = $this->purger->purge([
            "pattern" => "/blog/*",
        ]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertContains("abc123", $result["keys_purged"]);
        $this->assertContains("def456", $result["keys_purged"]);

        // Verify correct files deleted
        $this->assertFalse($this->cacheFileExists("abc123"));
        $this->assertFalse($this->cacheFileExists("def456"));
        $this->assertTrue($this->cacheFileExists("ghi789"));
        $this->assertTrue($this->cacheFileExists("jkl012"));
    }

    public function testPurgeAll(): void
    {
        // Create multiple cache files
        $this->createCacheFile("key1");
        $this->createCacheFile("key2");
        $this->createCacheFile("key3");
        $this->createCacheFile("key4");
        $this->createCacheFile("key5");

        // Purge all
        $result = $this->purger->purge([
            "purge_all" => true,
        ]);

        $this->assertEquals(5, $result["purged_count"]);
        $this->assertCount(5, $result["keys_purged"]);
        $this->assertEmpty($result["errors"]);

        // Verify all files deleted
        $this->assertEquals(0, $this->purger->count());
    }

    // ========================================================================
    // PATTERN MATCHING TESTS
    // ========================================================================

    public function testPatternMatchingWildcardPrefix(): void
    {
        $this->createCacheFile("key1", "content", "/user/123/profile");
        $this->createCacheFile("key2", "content", "/user/456/profile");
        $this->createCacheFile("key3", "content", "/product/123");

        $result = $this->purger->purge(["pattern" => "/user/*"]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertTrue($this->cacheFileExists("key3"));
    }

    public function testPatternMatchingWildcardSuffix(): void
    {
        $this->createCacheFile("key1", "content", "/page/home");
        $this->createCacheFile("key2", "content", "/post/home");
        $this->createCacheFile("key3", "content", "/page/about");

        $result = $this->purger->purge(["pattern" => "*/home"]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertContains("key1", $result["keys_purged"]);
        $this->assertContains("key2", $result["keys_purged"]);
    }

    public function testPatternMatchingWildcardMiddle(): void
    {
        $this->createCacheFile("key1", "content", "/user/admin/settings");
        $this->createCacheFile("key2", "content", "/page/admin/dashboard");
        $this->createCacheFile("key3", "content", "/user/guest/profile");

        $result = $this->purger->purge(["pattern" => "*admin*"]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertTrue($this->cacheFileExists("key3"));
    }

    public function testPatternMatchingMultipleWildcards(): void
    {
        $this->createCacheFile("key1", "content", "/blog/2024/post/1");
        $this->createCacheFile("key2", "content", "/blog/2024/post/2");
        $this->createCacheFile("key3", "content", "/news/2024/article/1");

        $result = $this->purger->purge(["pattern" => "/blog/*/post/*"]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertTrue($this->cacheFileExists("key3"));
    }

    public function testPatternMatchingExactMatch(): void
    {
        $this->createCacheFile("key1", "content", "/exact/key");
        $this->createCacheFile("key2", "content", "/exact/key/other");

        $result = $this->purger->purge(["pattern" => "/exact/key"]);

        $this->assertEquals(1, $result["purged_count"]);
        $this->assertContains("key1", $result["keys_purged"]);
        $this->assertTrue($this->cacheFileExists("key2"));
    }

    public function testPatternMatchingSpecialCharacters(): void
    {
        $this->createCacheFile("key1", "content", "/key.with.dots");
        $this->createCacheFile("key2", "content", "/key-with-dashes");
        $this->createCacheFile("key3", "content", "/key_with_underscores");

        $result = $this->purger->purge(["pattern" => "/key.*"]);

        $this->assertEquals(1, $result["purged_count"]);
        $this->assertContains("key1", $result["keys_purged"]);
    }

    // ========================================================================
    // ERROR HANDLING TESTS
    // ========================================================================

    public function testPurgeNonExistentKeys(): void
    {
        $this->createCacheFile("existing_key");

        $result = $this->purger->purge([
            "keys" => ["existing_key", "nonexistent_key"],
        ]);

        // Should purge existing key, silently skip nonexistent
        $this->assertEquals(1, $result["purged_count"]);
        $this->assertContains("existing_key", $result["keys_purged"]);
        $this->assertEmpty($result["errors"]);
    }

    public function testPurgeInvalidKeyTypes(): void
    {
        $result = $this->purger->purge([
            "keys" => ["valid_key", 123, null, []],
        ]);

        // Should have errors for invalid types
        $this->assertNotEmpty($result["errors"]);
        $this->assertGreaterThanOrEqual(3, count($result["errors"]));
    }

    public function testPurgeInvalidInput(): void
    {
        $result = $this->purger->purge([]);

        $this->assertEquals(0, $result["purged_count"]);
        $this->assertNotEmpty($result["errors"]);
        $this->assertStringContainsString(
            "Invalid input",
            $result["errors"][0],
        );
    }

    public function testPurgeEmptyKeysArray(): void
    {
        $this->createCacheFile("test_key");

        $result = $this->purger->purge(["keys" => []]);

        $this->assertEquals(0, $result["purged_count"]);
        $this->assertEmpty($result["keys_purged"]);
        $this->assertTrue($this->cacheFileExists("test_key"));
    }

    public function testPurgePatternNoMatches(): void
    {
        $this->createCacheFile("key1", "content", "/test/page");

        $result = $this->purger->purge(["pattern" => "/nomatch/*"]);

        $this->assertEquals(0, $result["purged_count"]);
        $this->assertEmpty($result["keys_purged"]);
        $this->assertTrue($this->cacheFileExists("key1"));
    }

    public function testPatternMatchingSkipsFilesWithoutMetadata(): void
    {
        // Create files with and without metadata
        $this->createCacheFile("key1", "content", "/blog/post-1"); // Has URL metadata
        $this->createCacheFile("key2", "content"); // No URL metadata

        $result = $this->purger->purge(["pattern" => "/blog/*"]);

        // Should only purge key1, skip key2 gracefully
        $this->assertEquals(1, $result["purged_count"]);
        $this->assertContains("key1", $result["keys_purged"]);
        $this->assertTrue($this->cacheFileExists("key2")); // Still exists
        $this->assertEmpty($result["errors"]); // No errors
    }

    // ========================================================================
    // EDGE CASES
    // ========================================================================

    public function testPurgeEmptyCache(): void
    {
        $result = $this->purger->purge(["purge_all" => true]);

        $this->assertEquals(0, $result["purged_count"]);
        $this->assertEmpty($result["keys_purged"]);
        $this->assertEmpty($result["errors"]);
    }

    public function testPurgeWithSharding(): void
    {
        // Create files in different shards
        $this->createCacheFile("aa123456"); // Shard: aa
        $this->createCacheFile("bb234567"); // Shard: bb
        $this->createCacheFile("cc345678"); // Shard: cc

        $result = $this->purger->purge(["purge_all" => true]);

        $this->assertEquals(3, $result["purged_count"]);
    }

    public function testPurgeDuplicateKeys(): void
    {
        $this->createCacheFile("duplicate_key");

        $result = $this->purger->purge([
            "keys" => ["duplicate_key", "duplicate_key", "duplicate_key"],
        ]);

        // Should only count once even if specified multiple times
        $this->assertEquals(1, $result["purged_count"]);
    }

    public function testPurgeVeryLongPattern(): void
    {
        $longUrl = "/" . str_repeat("a", 200);
        $this->createCacheFile("test_key", "content", $longUrl);

        $result = $this->purger->purge(["pattern" => $longUrl]);

        $this->assertEquals(1, $result["purged_count"]);
    }

    // ========================================================================
    // STATISTICS AND UTILITY TESTS
    // ========================================================================

    public function testGetLastStats(): void
    {
        $this->createCacheFile("test_key");

        $this->purger->purge(["keys" => ["test_key"]]);
        $stats = $this->purger->getLastStats();

        $this->assertArrayHasKey("purged_count", $stats);
        $this->assertArrayHasKey("keys_purged", $stats);
        $this->assertArrayHasKey("errors", $stats);
        $this->assertEquals(1, $stats["purged_count"]);
    }

    public function testCount(): void
    {
        $this->createCacheFile("key1");
        $this->createCacheFile("key2");
        $this->createCacheFile("key3");

        $this->assertEquals(3, $this->purger->count());

        $this->purger->purge(["keys" => ["key1"]]);

        $this->assertEquals(2, $this->purger->count());
    }

    public function testIsWritable(): void
    {
        $this->assertTrue($this->purger->isWritable());
    }

    public function testGetCacheDir(): void
    {
        $this->assertEquals($this->testCacheDir, $this->purger->getCacheDir());
    }

    // ========================================================================
    // INTEGRATION TESTS
    // ========================================================================

    public function testMultiplePurgeOperations(): void
    {
        // Create diverse cache
        $this->createCacheFile("key1", "content", "/blog/post-1");
        $this->createCacheFile("key2", "content", "/blog/post-2");
        $this->createCacheFile("user_123", "content", "/user/123");
        $this->createCacheFile("product_456", "content", "/product/456");
        $this->createCacheFile("temp_data", "content", "/temp/data");

        // Purge blogs by pattern
        $result1 = $this->purger->purge(["pattern" => "/blog/*"]);
        $this->assertEquals(2, $result1["purged_count"]);

        // Purge specific key
        $result2 = $this->purger->purge(["keys" => ["user_123"]]);
        $this->assertEquals(1, $result2["purged_count"]);

        // Verify remaining
        $this->assertEquals(2, $this->purger->count());
        $this->assertTrue($this->cacheFileExists("product_456"));
        $this->assertTrue($this->cacheFileExists("temp_data"));
    }

    public function testPurgeAndRecreate(): void
    {
        $key = "test_key";

        // Create, purge, recreate
        $this->createCacheFile($key, "content1");
        $this->purger->purge(["keys" => [$key]]);
        $this->assertFalse($this->cacheFileExists($key));

        $this->createCacheFile($key, "content2");
        $this->assertTrue($this->cacheFileExists($key));
    }

    // ========================================================================
    // PERFORMANCE BENCHMARKS
    // ========================================================================

    public function testPerformanceBenchmark(): void
    {
        $startTime = microtime(true);

        // Create 10,000 cache entries
        for ($i = 0; $i < 10000; $i++) {
            $key = sprintf("%032d", $i); // 32-char key for proper sharding
            $this->createCacheFile($key);
        }

        // Purge all entries
        $purgeStart = microtime(true);
        $result = $this->purger->purge(["purge_all" => true]);
        $purgeTime = (microtime(true) - $purgeStart) * 1000;

        $totalTime = (microtime(true) - $startTime) * 1000;

        // Assertions
        $this->assertEquals(10000, $result["purged_count"]);
        $this->assertEmpty($result["errors"]);
        $this->assertEquals(0, $this->purger->count());

        // Performance target: <3000ms (3 seconds) - conservative for test environment
        $this->assertLessThan(
            3000,
            $purgeTime,
            "Purge took {$purgeTime}ms (target: <3000ms)",
        );

        // Output benchmark results
        echo sprintf(
            "\n✓ Performance: Purged 10,000 entries in %.0fms (%.0f entries/sec)\n",
            $purgeTime,
            10000 / ($purgeTime / 1000),
        );
    }

    public function testPatternPerformance(): void
    {
        // Create 5,000 entries with different patterns
        for ($i = 0; $i < 2500; $i++) {
            $key1 = sprintf("%032d", $i);
            $key2 = sprintf("%032d", $i + 2500);
            $this->createCacheFile(
                $key1,
                "content",
                "/blog/" . sprintf("%08d", $i),
            );
            $this->createCacheFile(
                $key2,
                "content",
                "/user/" . sprintf("%08d", $i),
            );
        }

        $startTime = microtime(true);
        $result = $this->purger->purge(["pattern" => "/blog/*"]);
        $purgeTime = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(2500, $result["purged_count"]);
        $this->assertEquals(2500, $this->purger->count());

        // Should still be reasonably fast
        $this->assertLessThan(
            3000,
            $purgeTime,
            "Pattern purge took {$purgeTime}ms (target: <3000ms)",
        );

        echo sprintf(
            "\n✓ Pattern Performance: Purged 2,500/5,000 entries in %.0fms\n",
            $purgeTime,
        );
    }

    public function testBatchKeyPerformance(): void
    {
        // Create 1,000 entries
        $keys = [];
        for ($i = 0; $i < 1000; $i++) {
            $key = sprintf("%032d", $i);
            $this->createCacheFile($key);
            $keys[] = $key;
        }

        $startTime = microtime(true);
        $result = $this->purger->purge(["keys" => $keys]);
        $purgeTime = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(1000, $result["purged_count"]);

        echo sprintf(
            "\n✓ Batch Key Performance: Purged 1,000 specific keys in %.0fms\n",
            $purgeTime,
        );
    }

    // ========================================================================
    // REAL-WORLD SCENARIOS
    // ========================================================================

    public function testPurgeUserSpecificCache(): void
    {
        // Simulate user-specific cache entries
        $this->createCacheFile("key1", "content", "/user/123/profile");
        $this->createCacheFile("key2", "content", "/user/123/settings");
        $this->createCacheFile("key3", "content", "/user/123/preferences");
        $this->createCacheFile("key4", "content", "/user/456/profile");

        // Purge all cache for user 123
        $result = $this->purger->purge(["pattern" => "/user/123/*"]);

        $this->assertEquals(3, $result["purged_count"]);
        $this->assertTrue($this->cacheFileExists("key4"));
    }

    public function testPurgeExpiredSessionCache(): void
    {
        // Simulate session cache with timestamps
        $this->createCacheFile("key1", "content", "/session/2024-01-15/abc");
        $this->createCacheFile("key2", "content", "/session/2024-01-15/def");
        $this->createCacheFile("key3", "content", "/session/2024-01-16/ghi");

        // Purge old sessions from specific date
        $result = $this->purger->purge(["pattern" => "/session/2024-01-15/*"]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertTrue($this->cacheFileExists("key3"));
    }

    public function testPurgeByLanguageVariant(): void
    {
        // Simulate multi-language cache
        $this->createCacheFile("key1", "content", "/page/home-en");
        $this->createCacheFile("key2", "content", "/page/home-es");
        $this->createCacheFile("key3", "content", "/page/about-en");
        $this->createCacheFile("key4", "content", "/page/about-es");

        // Purge Spanish variants
        $result = $this->purger->purge(["pattern" => "*-es"]);

        $this->assertEquals(2, $result["purged_count"]);
        $this->assertTrue($this->cacheFileExists("key1"));
        $this->assertTrue($this->cacheFileExists("key3"));
    }

    public function testHybridFunctionality(): void
    {
        // Test both modes work together:
        // 1. Keys mode (MD5 hashes - from Box 7)
        // 2. Pattern mode (URLs - for admin panel)

        // Create cache files with MD5-style keys and URLs in metadata
        $this->createCacheFile(
            "5f8e9c2a1b3d4e6f7a8b9c0d1e2f3a4b",
            "content",
            "/blog/post-1",
        );
        $this->createCacheFile(
            "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
            "content",
            "/blog/post-2",
        );
        $this->createCacheFile(
            "9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4",
            "content",
            "/user/123",
        );
        $this->createCacheFile(
            "3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8",
            "content",
            "/product/456",
        );

        // Mode 1: Purge by specific MD5 keys (Box 7 integration)
        $result1 = $this->purger->purge([
            "keys" => [
                "5f8e9c2a1b3d4e6f7a8b9c0d1e2f3a4b",
                "9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4",
            ],
        ]);

        $this->assertEquals(2, $result1["purged_count"]);
        $this->assertFalse(
            $this->cacheFileExists("5f8e9c2a1b3d4e6f7a8b9c0d1e2f3a4b"),
        );
        $this->assertFalse(
            $this->cacheFileExists("9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4"),
        );

        // Mode 2: Purge by URL pattern (admin panel)
        $result2 = $this->purger->purge([
            "pattern" => "/blog/*",
        ]);

        $this->assertEquals(1, $result2["purged_count"]); // Only blog/post-2 remains
        $this->assertFalse(
            $this->cacheFileExists("a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"),
        );

        // Verify /product/456 still exists
        $this->assertTrue(
            $this->cacheFileExists("3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8"),
        );
    }
}
