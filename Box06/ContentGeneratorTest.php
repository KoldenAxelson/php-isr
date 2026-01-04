<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "ContentGenerator.php";

/**
 * Comprehensive test suite for ContentGenerator
 *
 * Tests cover:
 * - Basic output capture (echo and return)
 * - Error handling
 * - Timeout detection
 * - Batch processing
 * - Fallback mechanisms
 * - Edge cases and validation
 * - Performance benchmarks
 */
class ContentGeneratorTest extends TestCase
{
    private ContentGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ContentGenerator();
    }

    // ============================================================
    // BASIC FUNCTIONALITY TESTS
    // ============================================================

    public function testBasicEchoCapture(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo "<html><body>Hello World</body></html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals(
            "<html><body>Hello World</body></html>",
            $result["html"],
        );
        $this->assertNull($result["error"]);
        $this->assertIsInt($result["generation_time_ms"]);
        $this->assertGreaterThanOrEqual(0, $result["generation_time_ms"]);
    }

    public function testBasicReturnCapture(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                return "<html><body>Returned Content</body></html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals(
            "<html><body>Returned Content</body></html>",
            $result["html"],
        );
        $this->assertNull($result["error"]);
    }

    public function testMixedEchoAndReturn(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo "<html>";
                echo "<body>";
                return "</body></html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        // When both echo and return are used, return takes precedence
        $this->assertEquals("</body></html>", $result["html"]);
    }

    public function testMultipleEchoStatements(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo "<html>";
                echo "<head><title>Test</title></head>";
                echo "<body>";
                echo "<h1>Hello</h1>";
                echo "</body>";
                echo "</html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals(
            "<html><head><title>Test</title></head><body><h1>Hello</h1></body></html>",
            $result["html"],
        );
    }

    // ============================================================
    // ERROR HANDLING TESTS
    // ============================================================

    public function testExceptionHandling(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                throw new RuntimeException("Something went wrong");
            },
        ]);

        $this->assertFalse($result["success"]);
        $this->assertEquals("", $result["html"]);
        $this->assertEquals("Something went wrong", $result["error"]);
    }

    public function testExceptionAfterOutput(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo "<html><body>";
                throw new RuntimeException("Error after output");
            },
        ]);

        $this->assertFalse($result["success"]);
        $this->assertEquals("", $result["html"]); // Output is discarded on error
        $this->assertEquals("Error after output", $result["error"]);
    }

    public function testInvalidGeneratorType(): void
    {
        $result = $this->generator->execute([
            "generator" => "not a callable",
        ]);

        $this->assertFalse($result["success"]);
        $this->assertEquals(
            "Generator must be a callable function",
            $result["error"],
        );
    }

    public function testMissingGenerator(): void
    {
        $result = $this->generator->execute([]);

        $this->assertFalse($result["success"]);
        $this->assertEquals(
            "Generator must be a callable function",
            $result["error"],
        );
    }

    // ============================================================
    // TIMEOUT TESTS
    // ============================================================

    public function testTimeoutDetection(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                usleep(150000); // 150ms
                echo "<html>content</html>";
            },
            "timeout" => 0.1, // 100ms timeout
        ]);

        // Execution completes but timeout is detected
        $this->assertFalse($result["success"]);
        $this->assertStringContainsString("Timeout exceeded", $result["error"]);
        $this->assertGreaterThan(100, $result["generation_time_ms"]);
    }

    public function testWithinTimeout(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                usleep(10000); // 10ms
                echo "<html>content</html>";
            },
            "timeout" => 1, // 1 second timeout
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals("<html>content</html>", $result["html"]);
        $this->assertNull($result["error"]);
        $this->assertLessThan(1000, $result["generation_time_ms"]);
    }

    public function testNoTimeout(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                usleep(50000); // 50ms
                echo "<html>content</html>";
            },
            // No timeout specified
        ]);

        $this->assertTrue($result["success"]);
        $this->assertNull($result["error"]);
    }

    // ============================================================
    // BATCH PROCESSING TESTS
    // ============================================================

    public function testBatchExecution(): void
    {
        $inputs = [
            [
                "generator" => function () {
                    echo "<html>Page 1</html>";
                },
            ],
            [
                "generator" => function () {
                    echo "<html>Page 2</html>";
                },
            ],
            [
                "generator" => function () {
                    echo "<html>Page 3</html>";
                },
            ],
        ];

        $results = $this->generator->executeBatch($inputs);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]["success"]);
        $this->assertTrue($results[1]["success"]);
        $this->assertTrue($results[2]["success"]);
        $this->assertEquals("<html>Page 1</html>", $results[0]["html"]);
        $this->assertEquals("<html>Page 2</html>", $results[1]["html"]);
        $this->assertEquals("<html>Page 3</html>", $results[2]["html"]);
    }

    public function testBatchWithMixedResults(): void
    {
        $inputs = [
            [
                "generator" => function () {
                    echo "<html>Success 1</html>";
                },
            ],
            [
                "generator" => function () {
                    throw new RuntimeException("Batch error");
                },
            ],
            [
                "generator" => function () {
                    echo "<html>Success 2</html>";
                },
            ],
        ];

        $results = $this->generator->executeBatch($inputs);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]["success"]);
        $this->assertFalse($results[1]["success"]);
        $this->assertTrue($results[2]["success"]);
        $this->assertEquals("Batch error", $results[1]["error"]);
    }

    public function testBatchPreservesIndices(): void
    {
        $inputs = [
            "page1" => [
                "generator" => function () {
                    echo "Content 1";
                },
            ],
            "page2" => [
                "generator" => function () {
                    echo "Content 2";
                },
            ],
        ];

        $results = $this->generator->executeBatch($inputs);

        $this->assertArrayHasKey("page1", $results);
        $this->assertArrayHasKey("page2", $results);
        $this->assertEquals("Content 1", $results["page1"]["html"]);
        $this->assertEquals("Content 2", $results["page2"]["html"]);
    }

    // ============================================================
    // FALLBACK TESTS
    // ============================================================

    public function testFallbackOnError(): void
    {
        $primaryInput = [
            "generator" => function () {
                throw new RuntimeException("Primary failed");
            },
        ];

        $fallback = function () {
            echo "<html>Fallback content</html>";
        };

        $result = $this->generator->executeWithFallback(
            $primaryInput,
            $fallback,
        );

        $this->assertTrue($result["success"]);
        $this->assertEquals("<html>Fallback content</html>", $result["html"]);
    }

    public function testNoFallbackWhenSuccess(): void
    {
        $primaryInput = [
            "generator" => function () {
                echo "<html>Primary content</html>";
            },
        ];

        $fallback = function () {
            echo "<html>Fallback content</html>";
        };

        $result = $this->generator->executeWithFallback(
            $primaryInput,
            $fallback,
        );

        $this->assertTrue($result["success"]);
        $this->assertEquals("<html>Primary content</html>", $result["html"]);
    }

    // ============================================================
    // OUTPUT VERIFICATION TESTS
    // ============================================================

    public function testVerifyOutputSuccess(): void
    {
        $generator = function () {
            echo "<html>content</html>";
        };

        $this->assertTrue($this->generator->verifyOutput($generator));
    }

    public function testVerifyOutputEmpty(): void
    {
        $generator = function () {
            echo "";
        };

        $this->assertFalse($this->generator->verifyOutput($generator));
    }

    public function testVerifyOutputWhitespaceOnly(): void
    {
        $generator = function () {
            echo "   ";
        };

        $this->assertFalse($this->generator->verifyOutput($generator));
    }

    public function testVerifyOutputOnError(): void
    {
        $generator = function () {
            throw new RuntimeException("Error");
        };

        $this->assertFalse($this->generator->verifyOutput($generator));
    }

    // ============================================================
    // EDGE CASES
    // ============================================================

    public function testEmptyOutput(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                // Generate nothing
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals("", $result["html"]);
    }

    public function testLargeOutput(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo str_repeat("<div>Large content block</div>", 10000);
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertGreaterThan(200000, strlen($result["html"]));
    }

    public function testSpecialCharacters(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo '<html>Special: & < > " \' é ñ 中文</html>';
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals(
            '<html>Special: & < > " \' é ñ 中文</html>',
            $result["html"],
        );
    }

    public function testNestedOutputBuffering(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                ob_start();
                echo "nested";
                $nested = ob_get_clean();
                echo "<html>" . $nested . "</html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals("<html>nested</html>", $result["html"]);
    }

    public function testPrintStatement(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                print "<html>Using print</html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals("<html>Using print</html>", $result["html"]);
    }

    public function testUrlContextPreserved(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo "<html>content</html>";
            },
            "url" => "/blog/post-123",
        ]);

        $this->assertTrue($result["success"]);
        $this->assertEquals("<html>content</html>", $result["html"]);
    }

    // ============================================================
    // TIMING TESTS
    // ============================================================

    public function testTimingAccuracy(): void
    {
        $expectedDelay = 100; // ms

        $result = $this->generator->execute([
            "generator" => function () use ($expectedDelay) {
                usleep($expectedDelay * 1000);
                echo "<html>content</html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        // Allow ±20ms margin for timing accuracy
        $this->assertGreaterThanOrEqual(
            $expectedDelay - 20,
            $result["generation_time_ms"],
        );
        $this->assertLessThanOrEqual(
            $expectedDelay + 20,
            $result["generation_time_ms"],
        );
    }

    public function testFastExecution(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo "x";
            },
        ]);

        $this->assertTrue($result["success"]);
        // Should be very fast (< 10ms)
        $this->assertLessThan(10, $result["generation_time_ms"]);
    }

    // ============================================================
    // PERFORMANCE BENCHMARKS
    // ============================================================

    public function testPerformanceBenchmark(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $result = $this->generator->execute([
                "generator" => function () {
                    echo "<html><body>Benchmark content</body></html>";
                },
            ]);
            $this->assertTrue($result["success"]);
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $avgTime = $totalTime / $iterations;

        // Should average < 1ms per execution
        $this->assertLessThan(
            1.0,
            $avgTime,
            "Average execution time: {$avgTime}ms",
        );

        echo "\n✓ Performance: {$iterations} executions in " .
            round($totalTime, 2) .
            "ms (avg: " .
            round($avgTime, 3) .
            "ms/execution)\n";
    }

    public function testBatchPerformance(): void
    {
        $batchSize = 100;
        $inputs = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $inputs[] = [
                "generator" => function () use ($i) {
                    echo "<html>Page {$i}</html>";
                },
            ];
        }

        $startTime = microtime(true);
        $results = $this->generator->executeBatch($inputs);
        $totalTime = (microtime(true) - $startTime) * 1000;

        $this->assertCount($batchSize, $results);
        foreach ($results as $result) {
            $this->assertTrue($result["success"]);
        }

        // Batch should process quickly
        $this->assertLessThan(
            100,
            $totalTime,
            "Batch processing time: {$totalTime}ms",
        );

        echo "\n✓ Batch Performance: {$batchSize} items in " .
            round($totalTime, 2) .
            "ms\n";
    }

    // ============================================================
    // STRESS TESTS
    // ============================================================

    public function testComplexHtmlGeneration(): void
    {
        $result = $this->generator->execute([
            "generator" => function () {
                echo "<!DOCTYPE html>";
                echo '<html lang="en">';
                echo "<head>";
                echo '<meta charset="UTF-8">';
                echo "<title>Complex Page</title>";
                echo "<style>body { margin: 0; }</style>";
                echo "</head>";
                echo "<body>";
                for ($i = 0; $i < 100; $i++) {
                    echo "<div class='item-{$i}'>Item {$i}</div>";
                }
                echo "</body>";
                echo "</html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertStringContainsString("<!DOCTYPE html>", $result["html"]);
        $this->assertStringContainsString("Item 99", $result["html"]);
    }

    public function testGeneratorWithExternalDependencies(): void
    {
        $data = ["title" => "Test", "items" => ["A", "B", "C"]];

        $result = $this->generator->execute([
            "generator" => function () use ($data) {
                echo "<html><head><title>{$data["title"]}</title></head><body>";
                foreach ($data["items"] as $item) {
                    echo "<div>{$item}</div>";
                }
                echo "</body></html>";
            },
        ]);

        $this->assertTrue($result["success"]);
        $this->assertStringContainsString(
            "<title>Test</title>",
            $result["html"],
        );
        $this->assertStringContainsString("<div>A</div>", $result["html"]);
        $this->assertStringContainsString("<div>B</div>", $result["html"]);
        $this->assertStringContainsString("<div>C</div>", $result["html"]);
    }

    // ============================================================
    // CONSISTENCY TESTS
    // ============================================================

    public function testDeterministicOutput(): void
    {
        $generator = function () {
            echo "<html><body>Deterministic</body></html>";
        };

        $result1 = $this->generator->execute(["generator" => $generator]);
        $result2 = $this->generator->execute(["generator" => $generator]);

        $this->assertEquals($result1["html"], $result2["html"]);
        $this->assertEquals($result1["success"], $result2["success"]);
    }

    public function testMultipleInstancesIsolated(): void
    {
        $gen1 = new ContentGenerator();
        $gen2 = new ContentGenerator();

        $result1 = $gen1->execute([
            "generator" => function () {
                echo "Instance 1";
            },
        ]);

        $result2 = $gen2->execute([
            "generator" => function () {
                echo "Instance 2";
            },
        ]);

        $this->assertEquals("Instance 1", $result1["html"]);
        $this->assertEquals("Instance 2", $result2["html"]);
    }
}
