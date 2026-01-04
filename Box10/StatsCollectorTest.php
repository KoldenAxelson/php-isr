<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/StatsCollector.php";

class StatsCollectorTest extends TestCase
{
    private StatsCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new StatsCollector();
    }

    /**
     * Test basic cache hit recording
     */
    public function testRecordCacheHit(): void
    {
        $result = $this->collector->record([
            "event" => "cache_hit",
            "url" => "/blog/post-123",
            "timestamp" => 1234567890,
        ]);

        $this->assertTrue($result["recorded"]);
        $this->assertEquals(1, $result["current_stats"]["hits"]);
        $this->assertEquals(0, $result["current_stats"]["misses"]);
        $this->assertEquals(100.0, $result["current_stats"]["hit_rate"]);
    }

    /**
     * Test basic cache miss recording
     */
    public function testRecordCacheMiss(): void
    {
        $result = $this->collector->record([
            "event" => "cache_miss",
            "url" => "/blog/post-456",
        ]);

        $this->assertTrue($result["recorded"]);
        $this->assertEquals(0, $result["current_stats"]["hits"]);
        $this->assertEquals(1, $result["current_stats"]["misses"]);
        $this->assertEquals(0.0, $result["current_stats"]["hit_rate"]);
    }

    /**
     * Test stale serve recording (ISR pattern)
     */
    public function testRecordStaleServe(): void
    {
        $result = $this->collector->record([
            "event" => "stale_serve",
            "url" => "/product/123",
            "metadata" => ["age_seconds" => 30],
        ]);

        $this->assertTrue($result["recorded"]);
        $this->assertEquals(1, $result["current_stats"]["stale_serves"]);
    }

    /**
     * Test generation time recording
     */
    public function testRecordGeneration(): void
    {
        $result = $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.125],
        ]);

        $this->assertTrue($result["recorded"]);
        $stats = $result["current_stats"];

        $this->assertArrayHasKey("generation", $stats);
        $this->assertEquals(1, $stats["generation"]["count"]);
        $this->assertEquals(0.125, $stats["generation"]["total_time"]);
        $this->assertEquals(0.125, $stats["generation"]["avg_time"]);
        $this->assertEquals(0.125, $stats["generation"]["min_time"]);
        $this->assertEquals(0.125, $stats["generation"]["max_time"]);
    }

    /**
     * Test multiple generation recordings calculate correctly
     */
    public function testMultipleGenerations(): void
    {
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.1],
        ]);

        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.2],
        ]);

        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.3],
        ]);

        $stats = $this->collector->getStats();

        $this->assertEquals(3, $stats["generation"]["count"]);
        $this->assertEquals(0.6, $stats["generation"]["total_time"]);
        $this->assertEquals(0.2, $stats["generation"]["avg_time"]);
        $this->assertEquals(0.1, $stats["generation"]["min_time"]);
        $this->assertEquals(0.3, $stats["generation"]["max_time"]);
    }

    /**
     * Test hit rate calculation with mixed hits and misses
     */
    public function testHitRateCalculation(): void
    {
        // Record 7 hits and 3 misses = 70% hit rate
        for ($i = 0; $i < 7; $i++) {
            $this->collector->record(["event" => "cache_hit"]);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->collector->record(["event" => "cache_miss"]);
        }

        $stats = $this->collector->getStats();

        $this->assertEquals(7, $stats["hits"]);
        $this->assertEquals(3, $stats["misses"]);
        $this->assertEquals(10, $stats["total_requests"]);
        $this->assertEquals(70.0, $stats["hit_rate"]);
    }

    /**
     * Test hit rate is 0 when no requests recorded
     */
    public function testHitRateWithNoRequests(): void
    {
        $stats = $this->collector->getStats();

        $this->assertEquals(0.0, $stats["hit_rate"]);
        $this->assertEquals(0, $stats["total_requests"]);
    }

    /**
     * Test unknown event types are not recorded
     */
    public function testUnknownEventType(): void
    {
        $result = $this->collector->record([
            "event" => "unknown_event",
        ]);

        $this->assertFalse($result["recorded"]);
        $stats = $result["current_stats"];
        $this->assertEquals(0, $stats["hits"]);
        $this->assertEquals(0, $stats["misses"]);
    }

    /**
     * Test missing event type is not recorded
     */
    public function testMissingEventType(): void
    {
        $result = $this->collector->record([
            "url" => "/page",
        ]);

        $this->assertFalse($result["recorded"]);
    }

    /**
     * Test reset clears all statistics
     */
    public function testReset(): void
    {
        // Record some events
        $this->collector->record(["event" => "cache_hit"]);
        $this->collector->record(["event" => "cache_miss"]);
        $this->collector->record(["event" => "stale_serve"]);
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.5],
        ]);

        // Verify recorded
        $stats = $this->collector->getStats();
        $this->assertEquals(1, $stats["hits"]);

        // Reset
        $this->collector->reset();

        // Verify all cleared
        $stats = $this->collector->getStats();
        $this->assertEquals(0, $stats["hits"]);
        $this->assertEquals(0, $stats["misses"]);
        $this->assertEquals(0, $stats["stale_serves"]);
        $this->assertEquals(0, $stats["total_requests"]);
        $this->assertArrayNotHasKey("generation", $stats);
    }

    /**
     * Test batch recording
     */
    public function testRecordBatch(): void
    {
        $events = [
            ["event" => "cache_hit", "url" => "/page1"],
            ["event" => "cache_hit", "url" => "/page2"],
            ["event" => "cache_miss", "url" => "/page3"],
            ["event" => "stale_serve", "url" => "/page4"],
        ];

        $result = $this->collector->recordBatch($events);

        $this->assertEquals(4, $result["recorded"]);
        $this->assertEquals(0, $result["failed"]);
        $this->assertEquals(2, $result["current_stats"]["hits"]);
        $this->assertEquals(1, $result["current_stats"]["misses"]);
        $this->assertEquals(1, $result["current_stats"]["stale_serves"]);
    }

    /**
     * Test batch recording with some invalid events
     */
    public function testRecordBatchWithInvalidEvents(): void
    {
        $events = [
            ["event" => "cache_hit"],
            ["event" => "invalid_event"],
            ["event" => "cache_miss"],
            ["url" => "/no-event-type"],
        ];

        $result = $this->collector->recordBatch($events);

        $this->assertEquals(2, $result["recorded"]);
        $this->assertEquals(2, $result["failed"]);
    }

    /**
     * Test getSummary returns simplified stats
     */
    public function testGetSummary(): void
    {
        $this->collector->record(["event" => "cache_hit"]);
        $this->collector->record(["event" => "cache_hit"]);
        $this->collector->record(["event" => "cache_miss"]);
        $this->collector->record(["event" => "stale_serve"]);
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.25],
        ]);

        $summary = $this->collector->getSummary();

        $this->assertArrayHasKey("hit_rate", $summary);
        $this->assertArrayHasKey("total_requests", $summary);
        $this->assertArrayHasKey("stale_serves", $summary);
        $this->assertArrayHasKey("avg_generation_time", $summary);

        $this->assertEquals(66.7, $summary["hit_rate"]); // 2/3
        $this->assertEquals(3, $summary["total_requests"]);
        $this->assertEquals(1, $summary["stale_serves"]);
        $this->assertEquals(0.25, $summary["avg_generation_time"]);
    }

    /**
     * Test exportRaw returns raw counter data
     */
    public function testExportRaw(): void
    {
        $this->collector->record(["event" => "cache_hit"]);
        $this->collector->record(["event" => "cache_miss"]);
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.15],
        ]);

        $raw = $this->collector->exportRaw();

        $this->assertEquals(1, $raw["hits"]);
        $this->assertEquals(1, $raw["misses"]);
        $this->assertEquals(0, $raw["stale_serves"]);
        $this->assertEquals(1, $raw["generations"]);
        $this->assertEquals(0.15, $raw["total_generation_time"]);
        $this->assertEquals(0.15, $raw["min_generation_time"]);
        $this->assertEquals(0.15, $raw["max_generation_time"]);
    }

    /**
     * Test importRaw restores state
     */
    public function testImportRaw(): void
    {
        $data = [
            "hits" => 100,
            "misses" => 20,
            "stale_serves" => 5,
            "generations" => 50,
            "total_generation_time" => 12.5,
            "min_generation_time" => 0.1,
            "max_generation_time" => 0.8,
        ];

        $this->collector->importRaw($data);
        $stats = $this->collector->getStats();

        $this->assertEquals(100, $stats["hits"]);
        $this->assertEquals(20, $stats["misses"]);
        $this->assertEquals(5, $stats["stale_serves"]);
        $this->assertEquals(83.3, $stats["hit_rate"]); // 100/120
        $this->assertEquals(50, $stats["generation"]["count"]);
        $this->assertEquals(12.5, $stats["generation"]["total_time"]);
    }

    /**
     * Test export and import round trip
     */
    public function testExportImportRoundTrip(): void
    {
        // Record some events
        for ($i = 0; $i < 10; $i++) {
            $this->collector->record(["event" => "cache_hit"]);
        }
        $this->collector->record(["event" => "cache_miss"]);
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.2],
        ]);

        // Export
        $exported = $this->collector->exportRaw();

        // Create new collector and import
        $newCollector = new StatsCollector();
        $newCollector->importRaw($exported);

        // Verify stats match
        $this->assertEquals(
            $this->collector->getStats(),
            $newCollector->getStats(),
        );
    }

    /**
     * Test generation with invalid duration (zero)
     */
    public function testGenerationWithZeroDuration(): void
    {
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.0],
        ]);

        $stats = $this->collector->getStats();
        $this->assertArrayNotHasKey("generation", $stats);
    }

    /**
     * Test generation with negative duration
     */
    public function testGenerationWithNegativeDuration(): void
    {
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => -0.5],
        ]);

        $stats = $this->collector->getStats();
        $this->assertArrayNotHasKey("generation", $stats);
    }

    /**
     * Test generation without duration metadata
     */
    public function testGenerationWithoutDuration(): void
    {
        $this->collector->record([
            "event" => "generation",
            "metadata" => [],
        ]);

        $stats = $this->collector->getStats();
        $this->assertArrayNotHasKey("generation", $stats);
    }

    /**
     * Test ISR scenario: high cache hit rate with stale serves
     */
    public function testISRScenario(): void
    {
        // Simulate ISR pattern: mostly hits, some stale serves, few misses
        for ($i = 0; $i < 90; $i++) {
            $this->collector->record(["event" => "cache_hit"]);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->collector->record(["event" => "stale_serve"]);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->collector->record(["event" => "cache_miss"]);
            $this->collector->record([
                "event" => "generation",
                "metadata" => ["duration" => 0.1 + $i * 0.05],
            ]);
        }

        $stats = $this->collector->getStats();

        $this->assertEquals(90, $stats["hits"]);
        $this->assertEquals(5, $stats["misses"]);
        $this->assertEquals(5, $stats["stale_serves"]);
        $this->assertEquals(94.7, $stats["hit_rate"]); // 90/95
        $this->assertEquals(5, $stats["generation"]["count"]);
    }

    /**
     * Test performance: record 10,000 events in <100ms
     */
    public function testPerformanceBenchmark(): void
    {
        $iterations = 10000;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            // Mix of different event types
            $eventType = ["cache_hit", "cache_miss", "stale_serve"][$i % 3];
            $this->collector->record(["event" => $eventType]);

            // Every 10th record a generation
            if ($i % 10 === 0) {
                $this->collector->record([
                    "event" => "generation",
                    "metadata" => ["duration" => 0.1],
                ]);
            }
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to ms

        // Should complete in under 100ms
        $this->assertLessThan(
            100,
            $duration,
            sprintf(
                "Performance test failed: %d events took %.2fms (expected <100ms)",
                $iterations,
                $duration,
            ),
        );

        // Verify accuracy
        // With 10,000 iterations and i % 3 pattern:
        // i % 3 === 0 (cache_hit): occurs 3334 times (0,3,6,...,9999)
        // i % 3 === 1 (cache_miss): occurs 3333 times (1,4,7,...,9997)
        // i % 3 === 2 (stale_serve): occurs 3333 times (2,5,8,...,9998)
        $stats = $this->collector->getStats();
        $this->assertEquals(3334, $stats["hits"]);

        printf(
            "\n✓ Performance: %d events in %.2fms (~%.0f events/sec)\n",
            $iterations,
            $duration,
            $iterations / ($duration / 1000),
        );
    }

    /**
     * Test concurrent-like recording (rapid succession)
     */
    public function testRapidRecording(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->collector->record(["event" => "cache_hit"]);
        }

        $stats = $this->collector->getStats();
        $this->assertEquals(1000, $stats["hits"]);
        $this->assertEquals(100.0, $stats["hit_rate"]);
    }

    /**
     * Test edge case: only stale serves (no hits or misses)
     */
    public function testOnlyStaleServes(): void
    {
        $this->collector->record(["event" => "stale_serve"]);
        $this->collector->record(["event" => "stale_serve"]);

        $stats = $this->collector->getStats();

        $this->assertEquals(2, $stats["stale_serves"]);
        $this->assertEquals(0, $stats["total_requests"]); // Stale serves don't count as hits/misses
        $this->assertEquals(0.0, $stats["hit_rate"]);
    }

    /**
     * Test realistic ISR dashboard data
     */
    public function testRealisticDashboardScenario(): void
    {
        // Simulate 1 hour of traffic
        // 1000 requests: 970 hits, 30 misses, 20 stale serves
        // 970 / (970 + 30) = 970/1000 = 97.0%
        for ($i = 0; $i < 970; $i++) {
            $this->collector->record(["event" => "cache_hit"]);
        }

        for ($i = 0; $i < 30; $i++) {
            $this->collector->record(["event" => "cache_miss"]);
            $this->collector->record([
                "event" => "generation",
                "metadata" => ["duration" => 0.15 + rand(0, 100) / 1000],
            ]);
        }

        for ($i = 0; $i < 20; $i++) {
            $this->collector->record(["event" => "stale_serve"]);
        }

        $summary = $this->collector->getSummary();

        // Verify dashboard metrics make sense
        $this->assertEquals(97.0, $summary["hit_rate"]); // 970/1000
        $this->assertEquals(1000, $summary["total_requests"]);
        $this->assertEquals(20, $summary["stale_serves"]);
        $this->assertGreaterThan(0, $summary["avg_generation_time"]);
        $this->assertLessThan(1.0, $summary["avg_generation_time"]); // Should be sub-second

        $stats = $this->collector->getStats();
        $this->assertEquals(30, $stats["generation"]["count"]);
        $this->assertGreaterThan(0, $stats["generation"]["min_time"]);
        $this->assertLessThan(1.0, $stats["generation"]["max_time"]);
    }

    /**
     * Test floating point precision in calculations
     */
    public function testFloatingPointPrecision(): void
    {
        // Record generations with precise timings
        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.123456789],
        ]);

        $this->collector->record([
            "event" => "generation",
            "metadata" => ["duration" => 0.987654321],
        ]);

        $stats = $this->collector->getStats();

        // Values should be rounded to 4 decimal places
        $this->assertEquals(1.1111, $stats["generation"]["total_time"]);
        $this->assertEquals(0.5556, $stats["generation"]["avg_time"]);
    }

    /**
     * Test metadata is optional for non-generation events
     */
    public function testMetadataOptional(): void
    {
        $result = $this->collector->record([
            "event" => "cache_hit",
            "url" => "/page",
            "timestamp" => time(),
            "metadata" => ["age_seconds" => 30, "extra" => "data"],
        ]);

        $this->assertTrue($result["recorded"]);
        $this->assertEquals(1, $result["current_stats"]["hits"]);
    }
}
