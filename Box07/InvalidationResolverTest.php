<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/InvalidationResolver.php";
require_once __DIR__ . "/../Box02/CacheKeyGenerator.php";

/**
 * InvalidationResolverTest
 *
 * Tests event-based cache invalidation, dependency resolution, and performance
 */
class InvalidationResolverTest extends TestCase
{
    private InvalidationResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new InvalidationResolver();
    }

    // ========================================
    // BASIC FUNCTIONALITY
    // ========================================

    public function testBasicPostUpdateInvalidation(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "homepage" => ["latest_posts"],
                "category_page" => ["tech"],
                "author_page" => ["author_5"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertArrayHasKey("cache_keys_to_purge", $result);
        $this->assertArrayHasKey("reason", $result);
        $this->assertIsArray($result["cache_keys_to_purge"]);
        $this->assertIsString($result["reason"]);
        $this->assertNotEmpty($result["cache_keys_to_purge"]);
    }

    public function testCommentAddedInvalidation(): void
    {
        // Test with post_page dependency (realistic scenario)
        $event = [
            "event" => "comment_added",
            "entity_id" => 456,
            "entity_type" => "comment",
            "dependencies" => [
                "post_page" => [123], // The post this comment belongs to
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertNotEmpty($result["cache_keys_to_purge"]);
        $this->assertStringContainsString(
            "comment added",
            strtolower($result["reason"]),
        );

        // Should invalidate at least 2 pages: post page + recent comments
        $this->assertGreaterThanOrEqual(
            2,
            count($result["cache_keys_to_purge"]),
        );
    }

    public function testCommentAddedWithoutPostDependency(): void
    {
        // Edge case: comment_added without post_page dependency
        // Only invalidates recent_comments page
        $event = [
            "event" => "comment_added",
            "entity_id" => 456,
            "entity_type" => "comment",
            "dependencies" => [], // No post specified
        ];

        $result = $this->resolver->resolve($event);

        $this->assertNotEmpty($result["cache_keys_to_purge"]);
        // Only recent_comments page is invalidated
        $this->assertEquals(1, count($result["cache_keys_to_purge"]));
    }

    public function testUnknownEventType(): void
    {
        $event = [
            "event" => "unknown_event",
            "entity_id" => 123,
            "entity_type" => "post",
        ];

        $result = $this->resolver->resolve($event);

        $this->assertEmpty($result["cache_keys_to_purge"]);
        $this->assertStringContainsString(
            "No invalidation rules",
            $result["reason"],
        );
    }

    // ========================================
    // CACHE KEY GENERATION
    // ========================================

    public function testCacheKeysAreValidMD5Hashes(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        foreach ($result["cache_keys_to_purge"] as $key) {
            $this->assertMatchesRegularExpression(
                '/^[a-f0-9]{32}$/',
                $key,
                "Cache key should be valid MD5 hash",
            );
        }
    }

    public function testUniqueCacheKeysGenerated(): void
    {
        $event = [
            "event" => "post_created",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech", "ai", "programming"],
                "author_page" => ["john"],
                "tag_pages" => ["machine-learning", "tutorials"],
            ],
        ];

        $result = $this->resolver->resolve($event);
        $keys = $result["cache_keys_to_purge"];

        $uniqueKeys = array_unique($keys);

        $this->assertCount(
            count($uniqueKeys),
            $keys,
            "All cache keys should be unique",
        );
    }

    // ========================================
    // DEPENDENCY RESOLUTION
    // ========================================

    public function testHomepageDependency(): void
    {
        $event = [
            "event" => "post_created",
            "entity_id" => 999,
            "entity_type" => "post",
            "dependencies" => [
                "homepage" => ["latest_posts"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
    }

    public function testMultipleCategoryDependencies(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech", "ai", "programming"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        // Should generate keys for post page + 3 category pages
        $this->assertGreaterThanOrEqual(
            4,
            count($result["cache_keys_to_purge"]),
        );
    }

    public function testMultipleAuthorDependencies(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "author_page" => ["author_1", "author_2"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThanOrEqual(
            3,
            count($result["cache_keys_to_purge"]),
        );
    }

    public function testTagPageDependencies(): void
    {
        $event = [
            "event" => "post_created",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "tag_pages" => ["php", "caching", "performance"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
    }

    public function testArchiveDependencies(): void
    {
        $event = [
            "event" => "post_created",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "archive_page" => ["2024-12", "2024"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
    }

    public function testComplexDependencyGraph(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "homepage" => ["latest_posts", "featured"],
                "category_page" => ["tech", "ai"],
                "author_page" => ["john", "jane"],
                "tag_pages" => ["php", "caching", "performance"],
                "archive_page" => ["2024-12"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        // Should have many affected pages
        $this->assertGreaterThan(10, count($result["cache_keys_to_purge"]));
    }

    // ========================================
    // VARIANT HANDLING
    // ========================================

    public function testInvalidationWithSingleVariantSet(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
            "variants" => [["mobile" => true, "language" => "en"]],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
    }

    public function testInvalidationWithMultipleVariantSets(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
            "variants" => [
                ["mobile" => true, "language" => "en"],
                ["mobile" => false, "language" => "en"],
                ["mobile" => true, "language" => "es"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        // Each URL should generate a key for each variant set
        // Post page + category page = 2 URLs
        // 2 URLs × 3 variant sets = 6 keys
        $this->assertGreaterThanOrEqual(
            6,
            count($result["cache_keys_to_purge"]),
        );
    }

    public function testVariantKeysDifferFromNonVariantKeys(): void
    {
        $eventWithoutVariants = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
        ];

        $eventWithVariants = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
            "variants" => [["mobile" => true]],
        ];

        $result1 = $this->resolver->resolve($eventWithoutVariants);
        $result2 = $this->resolver->resolve($eventWithVariants);

        // Keys should be different
        $this->assertNotEquals(
            $result1["cache_keys_to_purge"],
            $result2["cache_keys_to_purge"],
        );
    }

    // ========================================
    // EVENT TYPE TESTS
    // ========================================

    public function testPostCreatedEvent(): void
    {
        $event = [
            "event" => "post_created",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "homepage" => ["latest_posts"],
                "category_page" => ["tech"],
                "author_page" => ["john"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
        $this->assertStringContainsString(
            "post created",
            strtolower($result["reason"]),
        );
    }

    public function testPostDeletedEvent(): void
    {
        $event = [
            "event" => "post_deleted",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
    }

    public function testCommentUpdatedEvent(): void
    {
        // When a comment is updated, pass which post it belongs to
        $event = [
            "event" => "comment_updated",
            "entity_id" => 456,
            "entity_type" => "comment",
            "dependencies" => [
                "post_page" => [123], // The post ID this comment belongs to
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertNotEmpty($result["cache_keys_to_purge"]);
    }

    public function testCommentDeletedEvent(): void
    {
        $event = [
            "event" => "comment_deleted",
            "entity_id" => 789,
            "entity_type" => "comment",
            "dependencies" => [
                "post_page" => [123], // The post this comment belonged to
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
        // Should invalidate post page + recent_comments
        $this->assertGreaterThanOrEqual(
            2,
            count($result["cache_keys_to_purge"]),
        );
    }

    public function testUserUpdatedEvent(): void
    {
        $event = [
            "event" => "user_updated",
            "entity_id" => 5,
            "entity_type" => "user",
            "dependencies" => [
                "author_page" => ["john"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertNotEmpty($result["cache_keys_to_purge"]);
    }

    public function testCategoryUpdatedEvent(): void
    {
        $event = [
            "event" => "category_updated",
            "entity_id" => 10,
            "entity_type" => "category",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
    }

    // ========================================
    // BATCH PROCESSING
    // ========================================

    public function testBatchResolution(): void
    {
        $events = [
            [
                "event" => "post_updated",
                "entity_id" => 123,
                "entity_type" => "post",
                "dependencies" => ["category_page" => ["tech"]],
            ],
            [
                "event" => "comment_added",
                "entity_id" => 456,
                "entity_type" => "comment",
                "dependencies" => [],
            ],
            [
                "event" => "post_created",
                "entity_id" => 789,
                "entity_type" => "post",
                "dependencies" => ["homepage" => ["latest"]],
            ],
        ];

        $results = $this->resolver->resolveBatch($events);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey(0, $results);
        $this->assertArrayHasKey(1, $results);
        $this->assertArrayHasKey(2, $results);

        foreach ($results as $result) {
            $this->assertArrayHasKey("cache_keys_to_purge", $result);
            $this->assertArrayHasKey("reason", $result);
        }
    }

    public function testBatchPreservesIndices(): void
    {
        $events = [
            "first" => [
                "event" => "post_updated",
                "entity_id" => 1,
                "entity_type" => "post",
                "dependencies" => [],
            ],
            "second" => [
                "event" => "post_updated",
                "entity_id" => 2,
                "entity_type" => "post",
                "dependencies" => [],
            ],
        ];

        $results = $this->resolver->resolveBatch($events);

        $this->assertArrayHasKey("first", $results);
        $this->assertArrayHasKey("second", $results);
    }

    // ========================================
    // CUSTOM RULES
    // ========================================

    public function testAddCustomRule(): void
    {
        $this->resolver->addRule("product_updated", [
            "product_page",
            "category_page",
            "search_results",
        ]);

        $affectedTypes = $this->resolver->getAffectedPageTypes(
            "product_updated",
        );

        $this->assertContains("product_page", $affectedTypes);
        $this->assertContains("category_page", $affectedTypes);
        $this->assertContains("search_results", $affectedTypes);
    }

    public function testGetRules(): void
    {
        $rules = $this->resolver->getRules();

        $this->assertIsArray($rules);
        $this->assertArrayHasKey("post_updated", $rules);
        $this->assertArrayHasKey("comment_added", $rules);
    }

    public function testGetAffectedPageTypes(): void
    {
        $types = $this->resolver->getAffectedPageTypes("post_updated");

        $this->assertIsArray($types);
        $this->assertContains("post_page", $types);
        $this->assertContains("homepage", $types);
    }

    public function testGetAffectedPageTypesForUnknownEvent(): void
    {
        $types = $this->resolver->getAffectedPageTypes("unknown_event");

        $this->assertIsArray($types);
        $this->assertEmpty($types);
    }

    // ========================================
    // ESTIMATION
    // ========================================

    public function testEstimateInvalidationCount(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech", "ai"],
                "author_page" => ["john"],
            ],
        ];

        $estimate = $this->resolver->estimateInvalidationCount($event);

        $this->assertGreaterThan(0, $estimate);
        $this->assertIsInt($estimate);
    }

    public function testEstimateWithVariants(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech"],
            ],
            "variants" => [["mobile" => true], ["mobile" => false]],
        ];

        $estimate = $this->resolver->estimateInvalidationCount($event);

        // Post page (1) + category page (1) = 2 URLs
        // 2 URLs × 2 variants = 4 keys
        $this->assertGreaterThanOrEqual(4, $estimate);
    }

    public function testEstimateAccuracyForCommentEvents(): void
    {
        // Test that estimation doesn't over-count for non-post entities
        $event = [
            "event" => "comment_added",
            "entity_id" => 456,
            "entity_type" => "comment", // Not a post!
            "dependencies" => [], // No post specified
        ];

        $estimate = $this->resolver->estimateInvalidationCount($event);

        // Should only count recent_comments (1)
        // Not post_page since entity_type !== 'post' and no dependency
        $this->assertEquals(1, $estimate);
    }

    public function testEstimateAccuracyWithPostDependency(): void
    {
        $event = [
            "event" => "comment_added",
            "entity_id" => 456,
            "entity_type" => "comment",
            "dependencies" => [
                "post_page" => [123], // One post specified
            ],
        ];

        $estimate = $this->resolver->estimateInvalidationCount($event);

        // Should count: recent_comments (1) + post_page dependency (1) = 2
        $this->assertEquals(2, $estimate);
    }

    // ========================================
    // EDGE CASES
    // ========================================

    public function testEventWithNoEntity(): void
    {
        $event = [
            "event" => "cache_clear_all",
            "dependencies" => [],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertIsArray($result["cache_keys_to_purge"]);
    }

    public function testEventWithNoDependencies(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
        ];

        $result = $this->resolver->resolve($event);

        // Should still invalidate the post page itself
        $this->assertGreaterThan(0, count($result["cache_keys_to_purge"]));
    }

    public function testEventWithEmptyDependencies(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [],
        ];

        $result = $this->resolver->resolve($event);

        $this->assertNotEmpty($result["cache_keys_to_purge"]);
    }

    public function testSingleDependencyAsString(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => "tech", // String instead of array
            ],
        ];

        $result = $this->resolver->resolve($event);

        // Should handle gracefully
        $this->assertIsArray($result["cache_keys_to_purge"]);
    }

    // ========================================
    // DETERMINISM
    // ========================================

    public function testDeterministicResolution(): void
    {
        $event = [
            "event" => "post_updated",
            "entity_id" => 123,
            "entity_type" => "post",
            "dependencies" => [
                "category_page" => ["tech", "ai"],
            ],
        ];

        $result1 = $this->resolver->resolve($event);
        $result2 = $this->resolver->resolve($event);
        $result3 = $this->resolver->resolve($event);

        $this->assertEquals(
            $result1["cache_keys_to_purge"],
            $result2["cache_keys_to_purge"],
        );
        $this->assertEquals(
            $result2["cache_keys_to_purge"],
            $result3["cache_keys_to_purge"],
        );
    }

    // ========================================
    // PERFORMANCE TESTS
    // ========================================

    public function testPerformanceBenchmark(): void
    {
        // Generate 1000 test events
        $events = [];
        $eventTypes = [
            "post_updated",
            "post_created",
            "comment_added",
            "post_deleted",
        ];
        $categories = ["tech", "ai", "programming", "data-science", "web-dev"];
        $authors = ["john", "jane", "bob", "alice"];

        for ($i = 0; $i < 1000; $i++) {
            $events[] = [
                "event" => $eventTypes[$i % 4],
                "entity_id" => $i,
                "entity_type" => "post",
                "dependencies" => [
                    "homepage" => ["latest_posts"],
                    "category_page" => [$categories[$i % 5]],
                    "author_page" => [$authors[$i % 4]],
                    "tag_pages" => ["tag-" . $i % 10],
                ],
            ];
        }

        // Benchmark
        $startTime = microtime(true);
        $results = $this->resolver->resolveBatch($events);
        $endTime = microtime(true);

        $elapsedMs = ($endTime - $startTime) * 1000;

        // Assertions
        $this->assertCount(1000, $results);
        $this->assertLessThan(
            500,
            $elapsedMs,
            sprintf("Performance: %.2fms (target: <500ms)", $elapsedMs),
        );

        // Verify all results are valid
        foreach ($results as $result) {
            $this->assertArrayHasKey("cache_keys_to_purge", $result);
            $this->assertIsArray($result["cache_keys_to_purge"]);
        }

        echo sprintf(
            "\n✓ Performance: 1,000 events in %.2fms (%.0f events/sec)\n",
            $elapsedMs,
            1000 / ($elapsedMs / 1000),
        );
    }

    public function testPerformanceWithComplexDependencies(): void
    {
        // Test with complex dependency graphs
        $events = [];

        for ($i = 0; $i < 100; $i++) {
            $events[] = [
                "event" => "post_updated",
                "entity_id" => $i,
                "entity_type" => "post",
                "dependencies" => [
                    "homepage" => ["latest_posts", "featured"],
                    "category_page" => ["tech", "ai", "programming"],
                    "author_page" => ["author_1", "author_2", "author_3"],
                    "tag_pages" => ["php", "cache", "performance", "web"],
                    "archive_page" => ["2024-12", "2024"],
                ],
            ];
        }

        $startTime = microtime(true);
        $results = $this->resolver->resolveBatch($events);
        $endTime = microtime(true);

        $elapsedMs = ($endTime - $startTime) * 1000;

        $this->assertCount(100, $results);
        $this->assertLessThan(
            100,
            $elapsedMs,
            sprintf(
                "Complex dependencies: %.2fms (should be <100ms for 100 events)",
                $elapsedMs,
            ),
        );
    }

    public function testPerformanceWithVariants(): void
    {
        $events = [];
        $variants = [
            ["mobile" => true, "language" => "en"],
            ["mobile" => false, "language" => "en"],
            ["mobile" => true, "language" => "es"],
            ["mobile" => false, "language" => "es"],
        ];

        for ($i = 0; $i < 250; $i++) {
            $events[] = [
                "event" => "post_updated",
                "entity_id" => $i,
                "entity_type" => "post",
                "dependencies" => [
                    "category_page" => ["tech"],
                ],
                "variants" => $variants,
            ];
        }

        $startTime = microtime(true);
        $results = $this->resolver->resolveBatch($events);
        $endTime = microtime(true);

        $elapsedMs = ($endTime - $startTime) * 1000;

        $this->assertCount(250, $results);
        $this->assertLessThan(
            200,
            $elapsedMs,
            sprintf(
                "With variants: %.2fms (should be <200ms for 250 events)",
                $elapsedMs,
            ),
        );
    }
}
