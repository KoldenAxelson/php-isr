<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "FileCacheStore.php";

class FileCacheStoreTest extends TestCase
{
    private string $testCacheDir;
    private FileCacheStore $store;

    protected function setUp(): void
    {
        // Create temporary cache directory for tests
        $this->testCacheDir = sys_get_temp_dir() . "/cache_test_" . uniqid();
        $this->store = new FileCacheStore($this->testCacheDir);
    }

    protected function tearDown(): void
    {
        // Clean up test cache directory
        $this->recursiveDelete($this->testCacheDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }

            $path = $dir . "/" . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ===== BASIC OPERATIONS =====

    public function testWriteAndRead(): void
    {
        $key = "test-key";
        $content = "<html><body>Test content</body></html>";

        $result = $this->store->write($key, $content);
        $this->assertTrue($result);

        $data = $this->store->read($key);
        $this->assertNotNull($data);
        $this->assertEquals($content, $data["content"]);
        $this->assertArrayHasKey("created_at", $data);
        $this->assertArrayHasKey("ttl", $data);
        $this->assertArrayHasKey("metadata", $data);
    }

    public function testWriteWithMetadata(): void
    {
        $key = "page-123";
        $content = "<html>Page content</html>";
        $metadata = [
            "url" => "/page/123",
            "size" => 1024,
            "headers" => ["Content-Type" => "text/html"],
        ];

        $this->store->write($key, $content, null, $metadata);

        $data = $this->store->read($key);
        $this->assertEquals($metadata, $data["metadata"]);
    }

    public function testReadNonExistent(): void
    {
        $data = $this->store->read("does-not-exist");
        $this->assertNull($data);
    }

    public function testDelete(): void
    {
        $key = "delete-test";
        $this->store->write($key, "content");

        $result = $this->store->delete($key);
        $this->assertTrue($result);

        $data = $this->store->read($key);
        $this->assertNull($data);
    }

    public function testDeleteNonExistent(): void
    {
        $result = $this->store->delete("does-not-exist");
        $this->assertFalse($result);
    }

    public function testExists(): void
    {
        $key = "exists-test";

        $this->assertFalse($this->store->exists($key));

        $this->store->write($key, "content");
        $this->assertTrue($this->store->exists($key));

        $this->store->delete($key);
        $this->assertFalse($this->store->exists($key));
    }

    // ===== TTL EXPIRATION =====

    public function testTTLExpiration(): void
    {
        $key = "ttl-test";
        $content = "expires soon";
        $ttl = 1; // 1 second

        $this->store->write($key, $content, $ttl);

        // Should exist immediately
        $data = $this->store->read($key);
        $this->assertNotNull($data);
        $this->assertEquals($content, $data["content"]);

        // Wait for expiration
        sleep(2);

        // Should be expired and deleted
        $data = $this->store->read($key);
        $this->assertNull($data);
    }

    public function testTTLZeroNeverExpires(): void
    {
        $key = "never-expire";
        $content = "immortal content";
        $ttl = 0; // Never expire

        $this->store->write($key, $content, $ttl);

        $data = $this->store->read($key);
        $this->assertNotNull($data);
        $this->assertEquals(0, $data["ttl"]);
    }

    public function testDefaultTTL(): void
    {
        $store = new FileCacheStore($this->testCacheDir, 7200); // 2 hours default

        $store->write("test", "content");
        $data = $store->read("test");

        $this->assertEquals(7200, $data["ttl"]);
    }

    // ===== OVERWRITING =====

    public function testOverwrite(): void
    {
        $key = "overwrite-test";

        $this->store->write($key, "original content");
        $data1 = $this->store->read($key);
        $createdAt1 = $data1["created_at"];

        sleep(1); // Ensure different timestamp

        $this->store->write($key, "new content");
        $data2 = $this->store->read($key);

        $this->assertEquals("new content", $data2["content"]);
        $this->assertGreaterThan($createdAt1, $data2["created_at"]);
    }

    // ===== BATCH OPERATIONS =====

    public function testWriteBatch(): void
    {
        $entries = [
            "key1" => ["content" => "content1", "ttl" => 3600],
            "key2" => [
                "content" => "content2",
                "metadata" => ["url" => "/page2"],
            ],
            "key3" => ["content" => "content3"],
        ];

        $results = $this->store->writeBatch($entries);

        $this->assertCount(3, $results);
        $this->assertTrue($results["key1"]);
        $this->assertTrue($results["key2"]);
        $this->assertTrue($results["key3"]);

        $data = $this->store->read("key2");
        $this->assertEquals("content2", $data["content"]);
        $this->assertEquals(["url" => "/page2"], $data["metadata"]);
    }

    public function testReadBatch(): void
    {
        $this->store->write("key1", "content1");
        $this->store->write("key2", "content2");
        $this->store->write("key3", "content3");

        $results = $this->store->readBatch(["key1", "key2", "missing", "key3"]);

        $this->assertCount(3, $results);
        $this->assertEquals("content1", $results["key1"]["content"]);
        $this->assertEquals("content2", $results["key2"]["content"]);
        $this->assertEquals("content3", $results["key3"]["content"]);
        $this->assertArrayNotHasKey("missing", $results);
    }

    // ===== LIST OPERATIONS =====

    public function testListKeys(): void
    {
        $this->store->write("key1", "content1");
        $this->store->write("key2", "content2");
        $this->store->write("key3", "content3");

        $keys = $this->store->list(false);

        $this->assertCount(3, $keys);
        $this->assertContains("key1", $keys);
        $this->assertContains("key2", $keys);
        $this->assertContains("key3", $keys);
    }

    public function testListWithContent(): void
    {
        $this->store->write("key1", "content1", null, ["meta" => "data1"]);
        $this->store->write("key2", "content2", null, ["meta" => "data2"]);

        $entries = $this->store->list(true);

        $this->assertCount(2, $entries);
        $this->assertEquals("content1", $entries["key1"]["content"]);
        $this->assertEquals("content2", $entries["key2"]["content"]);
        $this->assertEquals(["meta" => "data1"], $entries["key1"]["metadata"]);
    }

    public function testListExcludesExpired(): void
    {
        $this->store->write("valid", "content", 3600);
        $this->store->write("expired", "content", 1);

        sleep(2);

        $keys = $this->store->list(false);

        $this->assertCount(1, $keys);
        $this->assertContains("valid", $keys);
        $this->assertNotContains("expired", $keys);
    }

    // ===== CLEAR AND PRUNE =====

    public function testClear(): void
    {
        $this->store->write("key1", "content1");
        $this->store->write("key2", "content2");
        $this->store->write("key3", "content3");

        $count = $this->store->clear();

        $this->assertEquals(3, $count);
        $this->assertNull($this->store->read("key1"));
        $this->assertNull($this->store->read("key2"));
        $this->assertNull($this->store->read("key3"));
    }

    public function testPrune(): void
    {
        $this->store->write("valid1", "content", 3600);
        $this->store->write("valid2", "content", 3600);
        $this->store->write("expired1", "content", 1);
        $this->store->write("expired2", "content", 1);

        sleep(2);

        $count = $this->store->prune();

        $this->assertEquals(2, $count);
        $this->assertNotNull($this->store->read("valid1"));
        $this->assertNotNull($this->store->read("valid2"));
        $this->assertNull($this->store->read("expired1"));
        $this->assertNull($this->store->read("expired2"));
    }

    // ===== STATISTICS =====

    public function testGetStats(): void
    {
        $this->store->write("key1", "short");
        $this->store->write("key2", str_repeat("x", 1000));
        $this->store->write("expired", "content", 1);

        sleep(2);

        $stats = $this->store->getStats();

        $this->assertArrayHasKey("total_entries", $stats);
        $this->assertArrayHasKey("valid_entries", $stats);
        $this->assertArrayHasKey("expired_entries", $stats);
        $this->assertArrayHasKey("total_size_bytes", $stats);
        $this->assertArrayHasKey("total_size_mb", $stats);

        $this->assertEquals(3, $stats["total_entries"]);
        $this->assertEquals(2, $stats["valid_entries"]);
        $this->assertEquals(1, $stats["expired_entries"]);
        $this->assertGreaterThan(0, $stats["total_size_bytes"]);
    }

    // ===== SPECIAL CHARACTERS =====

    public function testSpecialCharactersInKey(): void
    {
        // Keys with special chars are sanitized
        $key = "key/with/../special@chars!";
        $content = "test content";

        $this->store->write($key, $content);
        $data = $this->store->read($key);

        $this->assertNotNull($data);
        $this->assertEquals($content, $data["content"]);
    }

    public function testUnicodeContent(): void
    {
        $key = "unicode-test";
        $content = "<html><body>Hello 世界 🌍 Привет</body></html>";

        $this->store->write($key, $content);
        $data = $this->store->read($key);

        $this->assertEquals($content, $data["content"]);
    }

    public function testLargeContent(): void
    {
        $key = "large-content";
        $content = str_repeat("<p>Large content block</p>", 10000); // ~200KB

        $result = $this->store->write($key, $content);
        $this->assertTrue($result);

        $data = $this->store->read($key);
        $this->assertEquals($content, $data["content"]);
    }

    // ===== SHARDING =====

    public function testShardedStorage(): void
    {
        $shardedStore = new FileCacheStore(
            $this->testCacheDir . "_sharded",
            3600,
            true, // Enable sharding
        );

        $shardedStore->write("test-key", "test content");
        $data = $shardedStore->read("test-key");

        $this->assertNotNull($data);
        $this->assertEquals("test content", $data["content"]);

        // Cleanup
        $this->recursiveDelete($this->testCacheDir . "_sharded");
    }

    // ===== EDGE CASES =====

    public function testEmptyContent(): void
    {
        $key = "empty";
        $this->store->write($key, "");

        $data = $this->store->read($key);
        $this->assertNotNull($data);
        $this->assertEquals("", $data["content"]);
    }

    public function testEmptyMetadata(): void
    {
        $key = "no-metadata";
        $this->store->write($key, "content", null, []);

        $data = $this->store->read($key);
        $this->assertEquals([], $data["metadata"]);
    }

    public function testComplexMetadata(): void
    {
        $metadata = [
            "nested" => [
                "deep" => [
                    "structure" => "value",
                ],
            ],
            "array" => [1, 2, 3],
            "bool" => true,
            "null" => null,
        ];

        $this->store->write("complex", "content", null, $metadata);
        $data = $this->store->read("complex");

        $this->assertEquals($metadata, $data["metadata"]);
    }

    // ===== CONCURRENT ACCESS =====

    public function testConcurrentWrites(): void
    {
        // Simulate concurrent writes to the same key
        $key = "concurrent-test";
        $iterations = 10;

        for ($i = 0; $i < $iterations; $i++) {
            $this->store->write($key, "content-$i");
        }

        // Should have last write
        $data = $this->store->read($key);
        $this->assertNotNull($data);
        $this->assertStringStartsWith("content-", $data["content"]);
    }

    public function testConcurrentReadsDontCorrupt(): void
    {
        $key = "read-test";
        $content = "stable content";

        $this->store->write($key, $content);

        // Multiple reads should all get same content
        for ($i = 0; $i < 100; $i++) {
            $data = $this->store->read($key);
            $this->assertEquals($content, $data["content"]);
        }
    }

    // ===== PERFORMANCE BENCHMARKS =====

    public function testPerformanceWrite1000Entries(): void
    {
        $startTime = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            $key = "perf-write-$i";
            $content = str_repeat("<p>Content for entry $i</p>", 10);
            $metadata = ["index" => $i, "url" => "/page/$i"];

            $this->store->write($key, $content, 3600, $metadata);
        }

        $duration = (microtime(true) - $startTime) * 1000; // Convert to ms

        // Should complete in <500ms
        $this->assertLessThan(
            500,
            $duration,
            "Writing 1000 entries took {$duration}ms (target: <500ms)",
        );

        echo "\n✓ Performance: 1000 writes in " . round($duration, 1) . "ms\n";
    }

    public function testPerformanceRead10000Entries(): void
    {
        // First, write 1000 entries
        for ($i = 0; $i < 1000; $i++) {
            $key = "perf-read-$i";
            $content = "<html><body>Content $i</body></html>";
            $this->store->write($key, $content, 3600);
        }

        // Now read each entry 10 times (10,000 total reads)
        $startTime = microtime(true);

        for ($iteration = 0; $iteration < 10; $iteration++) {
            for ($i = 0; $i < 1000; $i++) {
                $key = "perf-read-$i";
                $data = $this->store->read($key);
                $this->assertNotNull($data);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000; // Convert to ms

        // Should complete in <200ms
        $this->assertLessThan(
            200,
            $duration,
            "Reading 10,000 entries took {$duration}ms (target: <200ms)",
        );

        echo "\n✓ Performance: 10,000 reads in " . round($duration, 1) . "ms\n";
    }

    public function testPerformanceBatchWrite(): void
    {
        $entries = [];
        for ($i = 0; $i < 1000; $i++) {
            $entries["batch-$i"] = [
                "content" => "Content $i",
                "ttl" => 3600,
                "metadata" => ["index" => $i],
            ];
        }

        $startTime = microtime(true);
        $results = $this->store->writeBatch($entries);
        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertCount(1000, $results);

        // Batch should be reasonably fast
        $this->assertLessThan(
            600,
            $duration,
            "Batch write of 1000 entries took {$duration}ms",
        );

        echo "\n✓ Performance: 1000 batch writes in " .
            round($duration, 1) .
            "ms\n";
    }

    public function testPerformanceBatchRead(): void
    {
        // Write 1000 entries
        for ($i = 0; $i < 1000; $i++) {
            $this->store->write("batch-read-$i", "Content $i", 3600);
        }

        $keys = [];
        for ($i = 0; $i < 1000; $i++) {
            $keys[] = "batch-read-$i";
        }

        $startTime = microtime(true);
        $results = $this->store->readBatch($keys);
        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertCount(1000, $results);

        // Batch read should be reasonably fast
        $this->assertLessThan(
            300,
            $duration,
            "Batch read of 1000 entries took {$duration}ms",
        );

        echo "\n✓ Performance: 1000 batch reads in " .
            round($duration, 1) .
            "ms\n";
    }

    // ===== RELIABILITY =====

    public function testMultipleInstances(): void
    {
        // Two instances sharing same cache directory
        $store1 = new FileCacheStore($this->testCacheDir);
        $store2 = new FileCacheStore($this->testCacheDir);

        $store1->write("shared-key", "content from store1");
        $data = $store2->read("shared-key");

        $this->assertNotNull($data);
        $this->assertEquals("content from store1", $data["content"]);
    }

    public function testDirectoryCreation(): void
    {
        $newDir = $this->testCacheDir . "_new_" . uniqid();
        $store = new FileCacheStore($newDir);

        $this->assertTrue(is_dir($newDir));

        $store->write("test", "content");
        $this->assertNotNull($store->read("test"));

        $this->recursiveDelete($newDir);
    }
}
