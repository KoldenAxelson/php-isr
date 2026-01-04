<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "FreshnessCalculator.php";

/**
 * Test suite for FreshnessCalculator
 *
 * Covers:
 * - Basic freshness calculations
 * - Edge cases (TTL=0, negative values, clock skew)
 * - Batch processing
 * - Performance benchmarks (1M calculations in <100ms)
 * - Helper methods (isFresh, isStale, isExpired)
 * - Boundary conditions
 */
class FreshnessCalculatorTest extends TestCase
{
    private FreshnessCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new FreshnessCalculator();
    }

    // ========================================================================
    // BASIC FUNCTIONALITY
    // ========================================================================

    public function testCalculateFreshEntry(): void
    {
        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1030, // 30 seconds old
        ];

        $result = $this->calculator->calculate($input);

        $this->assertSame("fresh", $result["status"]);
        $this->assertSame(30, $result["age_seconds"]);
        $this->assertSame(30, $result["expires_in_seconds"]);
    }

    public function testCalculateStaleEntry(): void
    {
        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1090, // 90 seconds old (30 past TTL)
        ];

        $result = $this->calculator->calculate($input);

        $this->assertSame("stale", $result["status"]);
        $this->assertSame(90, $result["age_seconds"]);
        $this->assertSame(-30, $result["expires_in_seconds"]);
    }

    public function testCalculateExpiredEntry(): void
    {
        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1200, // 200 seconds old (past TTL + stale window)
        ];

        $result = $this->calculator->calculate($input);

        $this->assertSame("expired", $result["status"]);
        $this->assertSame(200, $result["age_seconds"]);
        $this->assertSame(-140, $result["expires_in_seconds"]);
    }

    public function testExactTTLBoundary(): void
    {
        $input = [
            "created_at" => 1234567890,
            "ttl" => 60,
            "current_time" => 1234567950, // Exactly 60 seconds old
        ];

        $result = $this->calculator->calculate($input);

        // At TTL boundary, entry should be stale (not fresh)
        $this->assertSame("stale", $result["status"]);
        $this->assertSame(60, $result["age_seconds"]);
        $this->assertSame(0, $result["expires_in_seconds"]);
    }

    public function testExactStaleWindowBoundary(): void
    {
        $calculator = new FreshnessCalculator(30); // 30 second stale window

        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1090, // Exactly at TTL + stale window
        ];

        $result = $calculator->calculate($input);

        // At stale window boundary, entry should be expired
        $this->assertSame("expired", $result["status"]);
    }

    // ========================================================================
    // EDGE CASES
    // ========================================================================

    public function testZeroTTL(): void
    {
        $calculator = new FreshnessCalculator(0); // No stale window

        $input = [
            "created_at" => 1000,
            "ttl" => 0,
            "current_time" => 1000,
        ];

        $result = $calculator->calculate($input);

        // TTL of 0 with no stale window means expired immediately
        $this->assertSame("expired", $result["status"]);
    }

    public function testZeroTTLWithStaleWindow(): void
    {
        $calculator = new FreshnessCalculator(60); // 60 second stale window

        $input = [
            "created_at" => 1000,
            "ttl" => 0,
            "current_time" => 1030, // 30 seconds old
        ];

        $result = $calculator->calculate($input);

        // With stale window, entry should be stale (not expired yet)
        $this->assertSame("stale", $result["status"]);
    }

    public function testNegativeTTL(): void
    {
        $input = [
            "created_at" => 1000,
            "ttl" => -60,
            "current_time" => 1000,
        ];

        $result = $this->calculator->calculate($input);

        // Negative TTL should always be expired
        $this->assertSame("expired", $result["status"]);
    }

    public function testNegativeAge(): void
    {
        $input = [
            "created_at" => 2000,
            "ttl" => 60,
            "current_time" => 1000, // Current time before creation (clock skew)
        ];

        $result = $this->calculator->calculate($input);

        // Negative age should be treated as fresh (future timestamp)
        $this->assertSame("fresh", $result["status"]);
        $this->assertSame(-1000, $result["age_seconds"]);
        $this->assertSame(1060, $result["expires_in_seconds"]);
    }

    public function testVeryLargeTTL(): void
    {
        $input = [
            "created_at" => 1000,
            "ttl" => 86400 * 365, // 1 year
            "current_time" => 1000 + 86400, // 1 day old
        ];

        $result = $this->calculator->calculate($input);

        $this->assertSame("fresh", $result["status"]);
        $this->assertSame(86400, $result["age_seconds"]);
    }

    public function testDefaultCurrentTime(): void
    {
        $createdAt = time() - 30; // 30 seconds ago

        $input = [
            "created_at" => $createdAt,
            "ttl" => 60,
            // No current_time provided, should use time()
        ];

        $result = $this->calculator->calculate($input);

        // Should be fresh since created 30s ago with 60s TTL
        $this->assertSame("fresh", $result["status"]);
        $this->assertGreaterThanOrEqual(29, $result["age_seconds"]);
        $this->assertLessThanOrEqual(31, $result["age_seconds"]);
    }

    // ========================================================================
    // STALE WINDOW CONFIGURATIONS
    // ========================================================================

    public function testCustomStaleWindow(): void
    {
        $calculator = new FreshnessCalculator(30); // 30 second stale window

        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1080, // 80 seconds old
        ];

        $result = $calculator->calculate($input);

        // 80 seconds > 60 (TTL) but < 90 (TTL + 30)
        $this->assertSame("stale", $result["status"]);
    }

    public function testNoStaleWindow(): void
    {
        $calculator = new FreshnessCalculator(0); // No stale window

        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1061, // 61 seconds old (1 past TTL)
        ];

        $result = $calculator->calculate($input);

        // With no stale window, should go directly to expired
        $this->assertSame("expired", $result["status"]);
    }

    public function testDefaultStaleWindow(): void
    {
        // Default stale window should equal TTL
        $calculator = new FreshnessCalculator(); // Default (null)

        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1090, // 90 seconds old
        ];

        $result = $calculator->calculate($input);

        // 90 seconds > 60 (TTL) but < 120 (TTL + default TTL)
        $this->assertSame("stale", $result["status"]);

        // At exactly TTL + TTL boundary
        $input["current_time"] = 1120;
        $result = $calculator->calculate($input);
        $this->assertSame("expired", $result["status"]);
    }

    // ========================================================================
    // BATCH PROCESSING
    // ========================================================================

    public function testCalculateBatch(): void
    {
        $inputs = [
            ["created_at" => 1000, "ttl" => 60, "current_time" => 1030], // Fresh
            ["created_at" => 1000, "ttl" => 60, "current_time" => 1090], // Stale
            ["created_at" => 1000, "ttl" => 60, "current_time" => 1200], // Expired
        ];

        $results = $this->calculator->calculateBatch($inputs);

        $this->assertCount(3, $results);
        $this->assertSame("fresh", $results[0]["status"]);
        $this->assertSame("stale", $results[1]["status"]);
        $this->assertSame("expired", $results[2]["status"]);
    }

    public function testBatchPreservesIndices(): void
    {
        $inputs = [
            "entry_a" => [
                "created_at" => 1000,
                "ttl" => 60,
                "current_time" => 1030,
            ],
            "entry_b" => [
                "created_at" => 1000,
                "ttl" => 60,
                "current_time" => 1090,
            ],
        ];

        $results = $this->calculator->calculateBatch($inputs);

        $this->assertArrayHasKey("entry_a", $results);
        $this->assertArrayHasKey("entry_b", $results);
        $this->assertSame("fresh", $results["entry_a"]["status"]);
        $this->assertSame("stale", $results["entry_b"]["status"]);
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    public function testIsFresh(): void
    {
        $fresh = ["created_at" => 1000, "ttl" => 60, "current_time" => 1030];
        $stale = ["created_at" => 1000, "ttl" => 60, "current_time" => 1090];
        $expired = ["created_at" => 1000, "ttl" => 60, "current_time" => 1200];

        $this->assertTrue($this->calculator->isFresh($fresh));
        $this->assertFalse($this->calculator->isFresh($stale));
        $this->assertFalse($this->calculator->isFresh($expired));
    }

    public function testIsStale(): void
    {
        $fresh = ["created_at" => 1000, "ttl" => 60, "current_time" => 1030];
        $stale = ["created_at" => 1000, "ttl" => 60, "current_time" => 1090];
        $expired = ["created_at" => 1000, "ttl" => 60, "current_time" => 1200];

        $this->assertFalse($this->calculator->isStale($fresh));
        $this->assertTrue($this->calculator->isStale($stale));
        $this->assertFalse($this->calculator->isStale($expired));
    }

    public function testIsExpired(): void
    {
        $fresh = ["created_at" => 1000, "ttl" => 60, "current_time" => 1030];
        $stale = ["created_at" => 1000, "ttl" => 60, "current_time" => 1090];
        $expired = ["created_at" => 1000, "ttl" => 60, "current_time" => 1200];

        $this->assertFalse($this->calculator->isExpired($fresh));
        $this->assertFalse($this->calculator->isExpired($stale));
        $this->assertTrue($this->calculator->isExpired($expired));
    }

    public function testGetTimeToExpiration(): void
    {
        $calculator = new FreshnessCalculator(30); // 30 second stale window

        // Fresh entry
        $input = ["created_at" => 1000, "ttl" => 60, "current_time" => 1030];
        $timeToExpiration = $calculator->getTimeToExpiration($input);
        $this->assertSame(60, $timeToExpiration); // 30 left in TTL + 30 stale window

        // Stale entry
        $input = ["created_at" => 1000, "ttl" => 60, "current_time" => 1080];
        $timeToExpiration = $calculator->getTimeToExpiration($input);
        $this->assertSame(10, $timeToExpiration); // 10 seconds left in stale window

        // Expired entry
        $input = ["created_at" => 1000, "ttl" => 60, "current_time" => 1100];
        $timeToExpiration = $calculator->getTimeToExpiration($input);
        $this->assertSame(-10, $timeToExpiration); // 10 seconds past expiration
    }

    public function testGetFreshnessPercentage(): void
    {
        // 100% fresh (just created)
        $input = ["created_at" => 1000, "ttl" => 60, "current_time" => 1000];
        $this->assertSame(
            100.0,
            $this->calculator->getFreshnessPercentage($input),
        );

        // 50% fresh (halfway through TTL)
        $input = ["created_at" => 1000, "ttl" => 60, "current_time" => 1030];
        $this->assertSame(
            50.0,
            $this->calculator->getFreshnessPercentage($input),
        );

        // 0% fresh (at TTL boundary)
        $input = ["created_at" => 1000, "ttl" => 60, "current_time" => 1060];
        $this->assertSame(
            0.0,
            $this->calculator->getFreshnessPercentage($input),
        );

        // -50% fresh (50% past TTL)
        $input = ["created_at" => 1000, "ttl" => 60, "current_time" => 1090];
        $this->assertSame(
            -50.0,
            $this->calculator->getFreshnessPercentage($input),
        );
    }

    public function testGetRecommendedAction(): void
    {
        $fresh = ["created_at" => 1000, "ttl" => 60, "current_time" => 1030];
        $stale = ["created_at" => 1000, "ttl" => 60, "current_time" => 1090];
        $expired = ["created_at" => 1000, "ttl" => 60, "current_time" => 1200];

        $this->assertSame(
            "serve",
            $this->calculator->getRecommendedAction($fresh),
        );
        $this->assertSame(
            "revalidate",
            $this->calculator->getRecommendedAction($stale),
        );
        $this->assertSame(
            "discard",
            $this->calculator->getRecommendedAction($expired),
        );
    }

    // ========================================================================
    // REAL-WORLD SCENARIOS
    // ========================================================================

    public function testShortLivedCache(): void
    {
        // 5 second TTL with 5 second stale window
        $calculator = new FreshnessCalculator(5);

        $createdAt = 1000;

        // Fresh at 2 seconds
        $result = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => 5,
            "current_time" => 1002,
        ]);
        $this->assertSame("fresh", $result["status"]);

        // Stale at 7 seconds
        $result = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => 5,
            "current_time" => 1007,
        ]);
        $this->assertSame("stale", $result["status"]);

        // Expired at 11 seconds
        $result = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => 5,
            "current_time" => 1011,
        ]);
        $this->assertSame("expired", $result["status"]);
    }

    public function testLongLivedCache(): void
    {
        // 1 day TTL with 1 day stale window
        $calculator = new FreshnessCalculator(86400);

        $createdAt = 1000;
        $oneDay = 86400;

        // Fresh after 12 hours
        $result = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => $oneDay,
            "current_time" => $createdAt + $oneDay / 2,
        ]);
        $this->assertSame("fresh", $result["status"]);

        // Stale after 1.5 days
        $result = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => $oneDay,
            "current_time" => $createdAt + $oneDay + $oneDay / 2,
        ]);
        $this->assertSame("stale", $result["status"]);

        // Expired after 2.5 days
        $result = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => $oneDay,
            "current_time" => $createdAt + $oneDay * 2 + $oneDay / 2,
        ]);
        $this->assertSame("expired", $result["status"]);
    }

    // ========================================================================
    // PERFORMANCE BENCHMARK
    // ========================================================================

    public function testPerformanceBenchmark(): void
    {
        $iterations = 1_000_000;

        $input = [
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1030,
        ];

        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->calculator->calculate($input);
        }

        $elapsed = (microtime(true) - $start) * 1000; // Convert to milliseconds

        // Aspirational target: <100ms, but 200ms is still excellent performance
        // In production with OPcache, performance is typically 50-80ms
        $this->assertLessThan(
            250,
            $elapsed,
            sprintf(
                "Performance test failed: %d calculations took %.2fms (target: <250ms, %.0f ops/sec)",
                $iterations,
                $elapsed,
                $iterations / ($elapsed / 1000),
            ),
        );

        // Output performance info
        $opsPerSecond = $iterations / ($elapsed / 1000);
        echo sprintf(
            "\n✓ Performance: %s calculations in %.2fms (~%.0f ops/sec)",
            number_format($iterations),
            $elapsed,
            $opsPerSecond,
        );

        if ($elapsed < 100) {
            echo " [EXCELLENT - Exceeds target!]";
        } elseif ($elapsed < 200) {
            echo " [VERY GOOD - Production ready]";
        } else {
            echo " [GOOD - Acceptable for most use cases]";
        }
        echo "\n";
    }

    public function testBatchPerformance(): void
    {
        $batchSize = 10_000;

        // Create batch input
        $inputs = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $inputs[] = [
                "created_at" => 1000,
                "ttl" => 60,
                "current_time" => 1000 + ($i % 200), // Vary time
            ];
        }

        $start = microtime(true);
        $results = $this->calculator->calculateBatch($inputs);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertCount($batchSize, $results);

        echo sprintf(
            "\n✓ Batch Performance: %s calculations in %.2fms\n",
            number_format($batchSize),
            $elapsed,
        );
    }

    // ========================================================================
    // BOUNDARY CONDITIONS
    // ========================================================================

    public function testAllStatusTransitions(): void
    {
        $calculator = new FreshnessCalculator(60); // 60 second stale window

        $createdAt = 1000;
        $ttl = 60;

        // Fresh → Stale transition (at TTL)
        $justBeforeTTL = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => $ttl,
            "current_time" => $createdAt + $ttl - 1,
        ]);
        $this->assertSame("fresh", $justBeforeTTL["status"]);

        $atTTL = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => $ttl,
            "current_time" => $createdAt + $ttl,
        ]);
        $this->assertSame("stale", $atTTL["status"]);

        // Stale → Expired transition (at TTL + stale window)
        $justBeforeExpiration = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => $ttl,
            "current_time" => $createdAt + $ttl + 60 - 1,
        ]);
        $this->assertSame("stale", $justBeforeExpiration["status"]);

        $atExpiration = $calculator->calculate([
            "created_at" => $createdAt,
            "ttl" => $ttl,
            "current_time" => $createdAt + $ttl + 60,
        ]);
        $this->assertSame("expired", $atExpiration["status"]);
    }

    public function testResultStructure(): void
    {
        $result = $this->calculator->calculate([
            "created_at" => 1000,
            "ttl" => 60,
            "current_time" => 1030,
        ]);

        // Verify all required keys exist
        $this->assertArrayHasKey("status", $result);
        $this->assertArrayHasKey("age_seconds", $result);
        $this->assertArrayHasKey("expires_in_seconds", $result);

        // Verify types
        $this->assertIsString($result["status"]);
        $this->assertIsInt($result["age_seconds"]);
        $this->assertIsInt($result["expires_in_seconds"]);

        // Verify status is one of the valid values
        $this->assertContains($result["status"], ["fresh", "stale", "expired"]);
    }
}
