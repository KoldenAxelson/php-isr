<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/CacheKeyGenerator.php";

/**
 * CacheKeyGeneratorTest
 *
 * Tests cache key generation, determinism, normalization, and performance
 */
class CacheKeyGeneratorTest extends TestCase
{
    private CacheKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new CacheKeyGenerator();
    }

    // ========================================
    // BASIC FUNCTIONALITY
    // ========================================

    public function testBasicKeyGeneration(): void
    {
        $input = [
            "url" => "/blog/post-123",
            "variants" => [],
        ];

        $key = $this->generator->generate($input);

        $this->assertIsString($key);
        $this->assertEquals(32, strlen($key), "MD5 should be 32 characters");
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/',
            $key,
            "Should be hex MD5",
        );
    }

    public function testKeyWithVariants(): void
    {
        $input = [
            "url" => "/blog/post-123",
            "variants" => [
                "mobile" => true,
                "language" => "es",
            ],
        ];

        $key = $this->generator->generate($input);

        $this->assertIsString($key);
        $this->assertEquals(32, strlen($key));
    }

    public function testEmptyUrl(): void
    {
        $input = [
            "url" => "",
            "variants" => [],
        ];

        $key = $this->generator->generate($input);

        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    // ========================================
    // DETERMINISM TESTS
    // ========================================

    public function testDeterminism(): void
    {
        $input = [
            "url" => "/blog/post-123",
            "variants" => [
                "mobile" => true,
                "language" => "es",
            ],
        ];

        $key1 = $this->generator->generate($input);
        $key2 = $this->generator->generate($input);
        $key3 = $this->generator->generate($input);

        $this->assertEquals($key1, $key2);
        $this->assertEquals($key2, $key3);
    }

    public function testDeterminismMethod(): void
    {
        $input1 = [
            "url" => "/page",
            "variants" => ["mobile" => true],
        ];

        $input2 = [
            "url" => "/page",
            "variants" => ["mobile" => true],
        ];

        $this->assertTrue(
            $this->generator->verifyDeterminism($input1, $input2),
        );
    }

    public function testVariantOrderIndependence(): void
    {
        // Variants in different order should produce same key
        $input1 = [
            "url" => "/page",
            "variants" => [
                "language" => "en",
                "mobile" => true,
                "country" => "US",
            ],
        ];

        $input2 = [
            "url" => "/page",
            "variants" => [
                "country" => "US",
                "mobile" => true,
                "language" => "en",
            ],
        ];

        $key1 = $this->generator->generate($input1);
        $key2 = $this->generator->generate($input2);

        $this->assertEquals($key1, $key2);
    }

    // ========================================
    // UNIQUENESS TESTS
    // ========================================

    public function testDifferentUrlsProduceDifferentKeys(): void
    {
        $key1 = $this->generator->generate([
            "url" => "/page1",
            "variants" => [],
        ]);
        $key2 = $this->generator->generate([
            "url" => "/page2",
            "variants" => [],
        ]);

        $this->assertNotEquals($key1, $key2);
    }

    public function testDifferentVariantsProduceDifferentKeys(): void
    {
        $input1 = [
            "url" => "/page",
            "variants" => ["mobile" => true],
        ];

        $input2 = [
            "url" => "/page",
            "variants" => ["mobile" => false],
        ];

        $key1 = $this->generator->generate($input1);
        $key2 = $this->generator->generate($input2);

        $this->assertNotEquals($key1, $key2);
    }

    public function testBooleanVsIntegerProducesDifferentKeys(): void
    {
        $input1 = ["url" => "/page", "variants" => ["mobile" => true]];
        $input2 = ["url" => "/page", "variants" => ["mobile" => 1]];

        $key1 = $this->generator->generate($input1);
        $key2 = $this->generator->generate($input2);

        $this->assertNotEquals(
            $key1,
            $key2,
            "Boolean true should differ from integer 1",
        );
    }

    public function testStringVariantCaseNormalization(): void
    {
        $input1 = ["url" => "/page", "variants" => ["language" => "EN"]];
        $input2 = ["url" => "/page", "variants" => ["language" => "en"]];

        $key1 = $this->generator->generate($input1);
        $key2 = $this->generator->generate($input2);

        $this->assertEquals(
            $key1,
            $key2,
            "String variants should be case-insensitive",
        );
    }

    public function testStringVariantWhitespaceNormalization(): void
    {
        $input1 = ["url" => "/page", "variants" => ["language" => " en "]];
        $input2 = ["url" => "/page", "variants" => ["language" => "en"]];

        $key1 = $this->generator->generate($input1);
        $key2 = $this->generator->generate($input2);

        $this->assertEquals(
            $key1,
            $key2,
            "String variants should trim whitespace",
        );
    }

    public function testMixedCaseVariantsNormalized(): void
    {
        $input1 = [
            "url" => "/page",
            "variants" => ["country" => "US", "language" => "EN"],
        ];
        $input2 = [
            "url" => "/page",
            "variants" => ["country" => "us", "language" => "en"],
        ];

        $key1 = $this->generator->generate($input1);
        $key2 = $this->generator->generate($input2);

        $this->assertEquals(
            $key1,
            $key2,
            "All string variants should be normalized",
        );
    }

    // ========================================
    // URL NORMALIZATION TESTS
    // ========================================

    public function testTrailingSlashNormalization(): void
    {
        $key1 = $this->generator->generate([
            "url" => "/blog/post",
            "variants" => [],
        ]);
        $key2 = $this->generator->generate([
            "url" => "/blog/post/",
            "variants" => [],
        ]);

        $this->assertEquals(
            $key1,
            $key2,
            "Trailing slashes should be normalized",
        );
    }

    public function testRootPathNormalization(): void
    {
        $key1 = $this->generator->generate(["url" => "/", "variants" => []]);
        $key2 = $this->generator->generate(["url" => "", "variants" => []]);

        $this->assertEquals($key1, $key2, "Empty URL should normalize to /");
    }

    public function testQueryParameterOrderNormalization(): void
    {
        $key1 = $this->generator->generate([
            "url" => "/search?b=2&a=1",
            "variants" => [],
        ]);
        $key2 = $this->generator->generate([
            "url" => "/search?a=1&b=2",
            "variants" => [],
        ]);

        $this->assertEquals($key1, $key2, "Query parameters should be sorted");
    }

    public function testHostCaseNormalization(): void
    {
        $key1 = $this->generator->generate([
            "url" => "https://EXAMPLE.COM/page",
            "variants" => [],
        ]);
        $key2 = $this->generator->generate([
            "url" => "https://example.com/page",
            "variants" => [],
        ]);

        $this->assertEquals($key1, $key2, "Host should be case-insensitive");
    }

    public function testProtocolCaseNormalization(): void
    {
        $key1 = $this->generator->generate([
            "url" => "HTTPS://example.com/page",
            "variants" => [],
        ]);
        $key2 = $this->generator->generate([
            "url" => "https://example.com/page",
            "variants" => [],
        ]);

        $this->assertEquals(
            $key1,
            $key2,
            "Protocol should be case-insensitive",
        );
    }

    public function testMultipleSlashNormalization(): void
    {
        $key1 = $this->generator->generate([
            "url" => "/path//to///page",
            "variants" => [],
        ]);
        $key2 = $this->generator->generate([
            "url" => "/path/to/page",
            "variants" => [],
        ]);

        $this->assertEquals(
            $key1,
            $key2,
            "Multiple slashes should be collapsed",
        );
    }

    // ========================================
    // COMPLEX INPUT TESTS
    // ========================================

    public function testComplexUrl(): void
    {
        $input = [
            "url" =>
                "https://example.com:8080/path/to/page?param1=value1&param2=value2#fragment",
            "variants" => ["mobile" => true],
        ];

        $key = $this->generator->generate($input);

        $this->assertIsString($key);
        $this->assertEquals(32, strlen($key));
    }

    public function testUrlWithSpecialCharacters(): void
    {
        $input = [
            "url" => "/search?q=hello+world&category=news%20%26%20media",
            "variants" => [],
        ];

        $key = $this->generator->generate($input);

        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    public function testMultipleVariantTypes(): void
    {
        $input = [
            "url" => "/page",
            "variants" => [
                "mobile" => true, // boolean
                "language" => "es", // string
                "user_id" => 12345, // integer
                "tags" => ["tech", "ai"], // array
                "premium" => false, // boolean
            ],
        ];

        $key = $this->generator->generate($input);

        $this->assertIsString($key);
        $this->assertEquals(32, strlen($key));
    }

    public function testNestedArrayVariants(): void
    {
        $input = [
            "url" => "/page",
            "variants" => [
                "filters" => [
                    "category" => "tech",
                    "tags" => ["ai", "ml"],
                ],
            ],
        ];

        $key = $this->generator->generate($input);

        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    // ========================================
    // BATCH PROCESSING
    // ========================================

    public function testBatchGeneration(): void
    {
        $inputs = [
            ["url" => "/page1", "variants" => []],
            ["url" => "/page2", "variants" => ["mobile" => true]],
            ["url" => "/page3", "variants" => ["language" => "fr"]],
        ];

        $keys = $this->generator->generateBatch($inputs);

        $this->assertCount(3, $keys);
        $this->assertEquals(
            0,
            array_keys($keys)[0],
            "Keys should preserve indices",
        );
        $this->assertNotEquals($keys[0], $keys[1]);
        $this->assertNotEquals($keys[1], $keys[2]);
    }

    // ========================================
    // COLLISION RESISTANCE
    // ========================================

    public function testNoCollisionsInDiverseDataset(): void
    {
        $keys = [];
        $testCases = [
            // Different URLs
            ["url" => "/page1", "variants" => []],
            ["url" => "/page2", "variants" => []],
            ["url" => "/blog/post-1", "variants" => []],
            ["url" => "/blog/post-2", "variants" => []],

            // Same URL, different variants
            ["url" => "/page", "variants" => ["mobile" => true]],
            ["url" => "/page", "variants" => ["mobile" => false]],
            ["url" => "/page", "variants" => ["language" => "en"]],
            ["url" => "/page", "variants" => ["language" => "es"]],

            // Multiple variant combinations
            [
                "url" => "/page",
                "variants" => ["mobile" => true, "language" => "en"],
            ],
            [
                "url" => "/page",
                "variants" => ["mobile" => true, "language" => "es"],
            ],
            [
                "url" => "/page",
                "variants" => ["mobile" => false, "language" => "en"],
            ],
            [
                "url" => "/page",
                "variants" => ["mobile" => false, "language" => "es"],
            ],

            // Different variant types
            [
                "url" => "/shop",
                "variants" => ["currency" => "USD", "country" => "US"],
            ],
            [
                "url" => "/shop",
                "variants" => ["currency" => "EUR", "country" => "DE"],
            ],
            [
                "url" => "/shop",
                "variants" => ["currency" => "GBP", "country" => "UK"],
            ],
        ];

        foreach ($testCases as $input) {
            $keys[] = $this->generator->generate($input);
        }

        $uniqueKeys = array_unique($keys);
        $collisions = count($keys) - count($uniqueKeys);

        $this->assertEquals(0, $collisions, "Found $collisions collision(s)");
    }

    public function testCollisionResistanceWithVariedInputs(): void
    {
        $keys = [];

        // Generate keys with systematically varied inputs
        for ($i = 0; $i < 1000; $i++) {
            $input = [
                "url" => "/page-" . $i,
                "variants" => [
                    "mobile" => $i % 2 === 0,
                    "language" => ["en", "es", "fr", "de", "ja"][$i % 5],
                    "country" => ["US", "MX", "FR", "DE", "JP"][$i % 5],
                ],
            ];

            $keys[] = $this->generator->generate($input);
        }

        $uniqueKeys = array_unique($keys);
        $collisions = count($keys) - count($uniqueKeys);

        $this->assertEquals(
            0,
            $collisions,
            "Found $collisions collision(s) in 1,000 keys",
        );
    }

    // ========================================
    // PERFORMANCE TESTS
    // ========================================

    public function testPerformanceBenchmark(): void
    {
        // Generate 100,000 test inputs
        $inputs = [];
        $urls = ["/page", "/blog/post", "/shop/product", "/api/data"];
        $languages = ["en", "es", "fr", "de", "ja"];

        for ($i = 0; $i < 100000; $i++) {
            $inputs[] = [
                "url" => $urls[$i % 4] . "-" . $i,
                "variants" => [
                    "mobile" => $i % 2 === 0,
                    "language" => $languages[$i % 5],
                ],
            ];
        }

        // Benchmark
        $startTime = microtime(true);
        $keys = $this->generator->generateBatch($inputs);
        $endTime = microtime(true);

        $elapsedMs = ($endTime - $startTime) * 1000;

        // Assertions
        $this->assertCount(100000, $keys);
        $this->assertLessThan(
            200,
            $elapsedMs,
            sprintf("Performance: %.2fms (target: <200ms)", $elapsedMs),
        );

        // Verify all keys are valid
        foreach ($keys as $key) {
            $this->assertNotEmpty($key);
            $this->assertEquals(32, strlen($key));
        }

        echo sprintf(
            "\n✓ Performance: 100,000 keys in %.2fms (%.0f keys/sec)\n",
            $elapsedMs,
            100000 / ($elapsedMs / 1000),
        );
    }

    public function testPerformanceWithComplexVariants(): void
    {
        // Test with more complex variant structures
        $inputs = [];

        for ($i = 0; $i < 10000; $i++) {
            $inputs[] = [
                "url" => "/api/endpoint-" . $i,
                "variants" => [
                    "mobile" => $i % 2 === 0,
                    "language" => "en",
                    "user_segment" => "premium",
                    "filters" => [
                        "category" => "tech",
                        "tags" => ["ai", "ml", "data"],
                    ],
                ],
            ];
        }

        $startTime = microtime(true);
        $keys = $this->generator->generateBatch($inputs);
        $endTime = microtime(true);

        $elapsedMs = ($endTime - $startTime) * 1000;

        $this->assertCount(10000, $keys);
        $this->assertLessThan(
            50,
            $elapsedMs,
            sprintf(
                "Complex variants: %.2fms (should be <50ms for 10k keys)",
                $elapsedMs,
            ),
        );
    }
}
