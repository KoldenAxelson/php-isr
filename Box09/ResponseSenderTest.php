<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/ResponseSender.php";

/**
 * Testable ResponseSender that doesn't actually send headers/output
 */
class TestableResponseSender extends ResponseSender
{
    public array $sentHeaders = [];
    public ?int $sentStatusCode = null;
    public string $sentOutput = "";
    public bool $headersAlreadySent = false;
    public string $acceptEncoding = "gzip, deflate, br"; // Default to supporting gzip

    protected function headersSent(): bool
    {
        return $this->headersAlreadySent;
    }

    protected function sendStatusCode(int $code): void
    {
        $this->sentStatusCode = $code;
    }

    protected function sendHeader(string $header): void
    {
        $this->sentHeaders[] = $header;
    }

    protected function sendOutput(string $content): void
    {
        $this->sentOutput = $content;
    }

    protected function getAcceptEncoding(): string
    {
        return $this->acceptEncoding;
    }

    public function reset(): void
    {
        $this->sentHeaders = [];
        $this->sentStatusCode = null;
        $this->sentOutput = "";
        $this->headersAlreadySent = false;
        $this->acceptEncoding = "gzip, deflate, br"; // Reset to default
    }
}

class ResponseSenderTest extends TestCase
{
    private TestableResponseSender $sender;

    protected function setUp(): void
    {
        $this->sender = new TestableResponseSender();
    }

    // ========================================
    // Basic Functionality Tests
    // ========================================

    public function testSendBasicResponse(): void
    {
        $input = [
            "html" => "<html><body>Hello World</body></html>",
            "status_code" => 200,
            "headers" => [
                "Content-Type" => "text/html",
            ],
            "compress" => false,
        ];

        $result = $this->sender->send($input);

        $this->assertTrue($result["sent"]);
        $this->assertGreaterThan(0, $result["bytes_sent"]);
        $this->assertFalse($result["compressed"]);
        $this->assertEquals(200, $this->sender->sentStatusCode);
        $this->assertEquals($input["html"], $this->sender->sentOutput);
    }

    public function testSendWithCustomStatusCode(): void
    {
        $input = [
            "html" => "<html><body>Not Found</body></html>",
            "status_code" => 404,
            "headers" => [],
            "compress" => false,
        ];

        $result = $this->sender->send($input);

        $this->assertTrue($result["sent"]);
        $this->assertEquals(404, $this->sender->sentStatusCode);
    }

    public function testSendWithMultipleHeaders(): void
    {
        $input = [
            "html" => "<html><body>Test</body></html>",
            "status_code" => 200,
            "headers" => [
                "Content-Type" => "text/html; charset=utf-8",
                "X-Cache" => "HIT",
                "X-Custom" => "Value",
            ],
            "compress" => false,
        ];

        $this->sender->send($input);

        $this->assertContains(
            "Content-Type: text/html; charset=utf-8",
            $this->sender->sentHeaders,
        );
        $this->assertContains("X-Cache: HIT", $this->sender->sentHeaders);
        $this->assertContains("X-Custom: Value", $this->sender->sentHeaders);
    }

    public function testSendIncludesContentLength(): void
    {
        $html = "<html><body>Test Content</body></html>";
        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => false,
        ];

        $this->sender->send($input);

        $expectedLength = strlen($html);
        $this->assertContains(
            "Content-Length: {$expectedLength}",
            $this->sender->sentHeaders,
        );
    }

    public function testSendEmptyContent(): void
    {
        $input = [
            "html" => "",
            "status_code" => 204, // No Content
            "headers" => [],
            "compress" => false,
        ];

        $result = $this->sender->send($input);

        $this->assertTrue($result["sent"]);
        $this->assertEquals(0, $result["bytes_sent"]);
        $this->assertEquals("", $this->sender->sentOutput);
    }

    // ========================================
    // Compression Tests
    // ========================================

    public function testCompressionEnabled(): void
    {
        // Create content large enough to compress (> 1KB)
        $html = str_repeat(
            "<p>This is a test paragraph with repeated content.</p>",
            100,
        );

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $result = $this->sender->send($input);

        $this->assertTrue($result["sent"]);
        $this->assertTrue($result["compressed"]);
        $this->assertLessThan(strlen($html), $result["bytes_sent"]);
        $this->assertContains(
            "Content-Encoding: gzip",
            $this->sender->sentHeaders,
        );
        $this->assertContains(
            "Vary: Accept-Encoding",
            $this->sender->sentHeaders,
        );
    }

    public function testCompressionDisabled(): void
    {
        $html = str_repeat("<p>Test content</p>", 100);

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => false,
        ];

        $result = $this->sender->send($input);

        $this->assertFalse($result["compressed"]);
        $this->assertEquals(strlen($html), $result["bytes_sent"]);
        $this->assertEquals($html, $this->sender->sentOutput);
    }

    public function testNoCompressionForSmallContent(): void
    {
        // Content smaller than 1KB should not be compressed
        $html = "<html><body>Small content</body></html>";

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $result = $this->sender->send($input);

        $this->assertFalse($result["compressed"]);
        $this->assertEquals($html, $this->sender->sentOutput);
    }

    public function testCompressionDecompressCorrectly(): void
    {
        $html = str_repeat(
            "<p>Compression test content with repetition.</p>",
            100,
        );

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $this->sender->send($input);

        // Decompress the output and verify it matches original
        $decompressed = gzdecode($this->sender->sentOutput);
        $this->assertEquals($html, $decompressed);
    }

    public function testCompressionRatio(): void
    {
        $original = str_repeat("AAAAAAAAAA", 1000); // Highly compressible
        $compressed = gzencode($original, 6);

        $ratio = $this->sender->getCompressionRatio($original, $compressed);

        $this->assertLessThan(0.1, $ratio); // Should compress to <10%
        $this->assertGreaterThan(0, $ratio);
    }

    public function testCompressionRatioWithEmptyContent(): void
    {
        $ratio = $this->sender->getCompressionRatio("", "");
        $this->assertEquals(1.0, $ratio);
    }

    // ========================================
    // Error Handling Tests
    // ========================================

    public function testInvalidStatusCodeTooLow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid status code: 99");

        $input = [
            "html" => "<html></html>",
            "status_code" => 99,
        ];

        $this->sender->send($input);
    }

    public function testInvalidStatusCodeTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid status code: 600");

        $input = [
            "html" => "<html></html>",
            "status_code" => 600,
        ];

        $this->sender->send($input);
    }

    public function testHeadersAlreadySent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Headers already sent");

        $this->sender->headersAlreadySent = true;

        $input = [
            "html" => "<html></html>",
            "status_code" => 200,
        ];

        $this->sender->send($input);
    }

    public function testValidStatusCodeRange(): void
    {
        // Test various valid status codes
        $validCodes = [100, 200, 201, 204, 301, 302, 304, 400, 404, 500, 503];

        foreach ($validCodes as $code) {
            $this->sender->reset();

            $input = [
                "html" => "<html></html>",
                "status_code" => $code,
            ];

            $result = $this->sender->send($input);
            $this->assertTrue($result["sent"]);
            $this->assertEquals($code, $this->sender->sentStatusCode);
        }
    }

    // ========================================
    // Prepare Method Tests
    // ========================================

    public function testPrepareWithoutSending(): void
    {
        $html = str_repeat("<p>Test content</p>", 100);

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => ["X-Test" => "Value"],
            "compress" => true,
        ];

        $prepared = $this->sender->prepare($input);

        // Should prepare but not send
        $this->assertEquals(200, $prepared["status_code"]);
        $this->assertTrue($prepared["compressed"]);
        $this->assertArrayHasKey("Content-Encoding", $prepared["headers"]);
        $this->assertArrayHasKey("Content-Length", $prepared["headers"]);

        // Nothing should have been sent
        $this->assertNull($this->sender->sentStatusCode);
        $this->assertEmpty($this->sender->sentHeaders);
        $this->assertEmpty($this->sender->sentOutput);
    }

    public function testPrepareCalculatesCorrectBytesSent(): void
    {
        $html = str_repeat("<p>Test</p>", 200);

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $prepared = $this->sender->prepare($input);

        $this->assertEquals(
            strlen($prepared["content"]),
            $prepared["bytes_sent"],
        );
        $this->assertEquals(
            $prepared["bytes_sent"],
            (int) $prepared["headers"]["Content-Length"],
        );
    }

    // ========================================
    // Default Values Tests
    // ========================================

    public function testDefaultStatusCode(): void
    {
        $input = [
            "html" => "<html></html>",
        ];

        $result = $this->sender->send($input);

        $this->assertEquals(200, $this->sender->sentStatusCode);
    }

    public function testDefaultHeaders(): void
    {
        $input = [
            "html" => "<html></html>",
            "status_code" => 200,
        ];

        $result = $this->sender->send($input);

        // Should only have Content-Length header
        $this->assertCount(1, $this->sender->sentHeaders);
        $this->assertStringStartsWith(
            "Content-Length:",
            $this->sender->sentHeaders[0],
        );
    }

    public function testDefaultCompressionDisabled(): void
    {
        $html = str_repeat("<p>Test</p>", 100);

        $input = [
            "html" => $html,
            "status_code" => 200,
        ];

        $result = $this->sender->send($input);

        $this->assertFalse($result["compressed"]);
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testLargeContent(): void
    {
        // 1MB of content
        $html = str_repeat("<p>Large content block with some text.</p>", 25000);

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $startTime = microtime(true);
        $result = $this->sender->send($input);
        $endTime = microtime(true);

        $this->assertTrue($result["sent"]);
        $this->assertTrue($result["compressed"]);
        $this->assertLessThan(strlen($html), $result["bytes_sent"]);

        // Should complete in reasonable time even for large content
        $duration = ($endTime - $startTime) * 1000; // Convert to ms
        $this->assertLessThan(100, $duration); // <100ms for 1MB
    }

    public function testSpecialCharactersInContent(): void
    {
        $html = "<html><body>Special: é à ñ 中文 🎉</body></html>";

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => false,
        ];

        $result = $this->sender->send($input);

        $this->assertTrue($result["sent"]);
        $this->assertEquals($html, $this->sender->sentOutput);
        $this->assertEquals(strlen($html), $result["bytes_sent"]);
    }

    public function testMultibyteCharactersWithCompression(): void
    {
        $html = str_repeat("日本語のテキスト ", 500);

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $result = $this->sender->send($input);

        $this->assertTrue($result["compressed"]);

        // Verify decompression works correctly with multibyte
        $decompressed = gzdecode($this->sender->sentOutput);
        $this->assertEquals($html, $decompressed);
    }

    // ========================================
    // Accept-Encoding Tests
    // ========================================

    public function testCompressionRequiresGzipSupport(): void
    {
        $html = str_repeat("<p>Test content</p>", 100);

        // Client doesn't support gzip
        $this->sender->acceptEncoding = "deflate, br";

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $result = $this->sender->send($input);

        // Should NOT compress
        $this->assertFalse($result["compressed"]);
        $this->assertEquals($html, $this->sender->sentOutput);
    }

    public function testCompressionWorksWhenGzipAccepted(): void
    {
        $html = str_repeat("<p>Test content</p>", 100);

        // Client supports gzip
        $this->sender->acceptEncoding = "gzip, deflate";

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $result = $this->sender->send($input);

        // Should compress
        $this->assertTrue($result["compressed"]);
        $this->assertLessThan(strlen($html), $result["bytes_sent"]);
    }

    public function testCompressionWithNoAcceptEncodingHeader(): void
    {
        $html = str_repeat("<p>Test content</p>", 100);

        // No Accept-Encoding header at all
        $this->sender->acceptEncoding = "";

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $result = $this->sender->send($input);

        // Should NOT compress
        $this->assertFalse($result["compressed"]);
    }

    public function testCompressionWithCaseInsensitiveGzip(): void
    {
        $html = str_repeat("<p>Test content</p>", 100);

        // HTTP headers are case-insensitive - all of these should work
        $encodings = ["gzip", "GZIP", "Gzip", "gZiP", "GzIp"];

        foreach ($encodings as $encoding) {
            $this->sender->reset();
            $this->sender->acceptEncoding = $encoding;

            $result = $this->sender->send([
                "html" => $html,
                "status_code" => 200,
                "headers" => [],
                "compress" => true,
            ]);

            // All variations should compress (stripos is case-insensitive)
            $this->assertTrue(
                $result["compressed"],
                "Failed to compress with Accept-Encoding: {$encoding}",
            );
        }
    }

    // ========================================
    // PSR-7 Support Tests
    // ========================================

    public function testSendPsr7BasicResponse(): void
    {
        $psr7Response = new class {
            public function getStatusCode(): int
            {
                return 200;
            }
            public function getHeaders(): array
            {
                return [
                    "Content-Type" => ["text/html"],
                    "X-Test" => ["Value"],
                ];
            }
            public function getBody()
            {
                return new class {
                    public function __toString(): string
                    {
                        return "<html><body>PSR-7 Response</body></html>";
                    }
                };
            }
        };

        $result = $this->sender->sendPsr7($psr7Response, false);

        $this->assertTrue($result["sent"]);
        $this->assertEquals(200, $this->sender->sentStatusCode);
        $this->assertContains(
            "Content-Type: text/html",
            $this->sender->sentHeaders,
        );
        $this->assertContains("X-Test: Value", $this->sender->sentHeaders);
    }

    public function testSendPsr7WithCompression(): void
    {
        $html = str_repeat("<p>PSR-7 test content</p>", 100);

        $psr7Response = new class ($html) {
            private $html;
            public function __construct($html)
            {
                $this->html = $html;
            }
            public function getStatusCode(): int
            {
                return 200;
            }
            public function getHeaders(): array
            {
                return ["Content-Type" => ["text/html"]];
            }
            public function getBody()
            {
                $html = $this->html;
                return new class ($html) {
                    private $html;
                    public function __construct($html)
                    {
                        $this->html = $html;
                    }
                    public function __toString(): string
                    {
                        return $this->html;
                    }
                };
            }
        };

        $result = $this->sender->sendPsr7($psr7Response, true);

        $this->assertTrue($result["compressed"]);
        $this->assertLessThan(strlen($html), $result["bytes_sent"]);
    }

    public function testSendPsr7WithMultiValueHeaders(): void
    {
        $psr7Response = new class {
            public function getStatusCode(): int
            {
                return 200;
            }
            public function getHeaders(): array
            {
                return [
                    "Content-Type" => ["text/html"],
                    "Set-Cookie" => ["session=abc123", "user=john"],
                ];
            }
            public function getBody()
            {
                return new class {
                    public function __toString(): string
                    {
                        return "<html></html>";
                    }
                };
            }
        };

        $result = $this->sender->sendPsr7($psr7Response, false);

        $this->assertTrue($result["sent"]);
        // Multi-value headers should be joined with comma
        $this->assertContains(
            "Set-Cookie: session=abc123, user=john",
            $this->sender->sentHeaders,
        );
    }

    public function testSendPsr7InvalidObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Response must implement PSR-7 ResponseInterface",
        );

        $notPsr7 = new class {};

        $this->sender->sendPsr7($notPsr7, false);
    }

    public function testSendPsr7WithDifferentStatusCodes(): void
    {
        $codes = [201, 204, 404, 500];

        foreach ($codes as $code) {
            $this->sender->reset();

            $psr7Response = new class ($code) {
                private $code;
                public function __construct($code)
                {
                    $this->code = $code;
                }
                public function getStatusCode(): int
                {
                    return $this->code;
                }
                public function getHeaders(): array
                {
                    return [];
                }
                public function getBody()
                {
                    return new class {
                        public function __toString(): string
                        {
                            return "<html></html>";
                        }
                    };
                }
            };

            $result = $this->sender->sendPsr7($psr7Response, false);

            $this->assertTrue($result["sent"]);
            $this->assertEquals($code, $this->sender->sentStatusCode);
        }
    }

    // ========================================
    // Performance Benchmark
    // ========================================

    public function testPerformanceBenchmark(): void
    {
        $html = "<html><body><h1>Test Page</h1><p>Content</p></body></html>";

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [
                "Content-Type" => "text/html",
                "X-Cache" => "HIT",
            ],
            "compress" => false,
        ];

        $iterations = 10000;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->sender->reset();
            $this->sender->send($input);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to ms
        $avgTime = $duration / $iterations;

        // Each send should be <1ms
        $this->assertLessThan(
            1.0,
            $avgTime,
            "Average time: {$avgTime}ms (should be <1ms)",
        );

        // Print performance info
        echo "\n✓ Performance: {$iterations} sends in " .
            round($duration, 2) .
            "ms ";
        echo "(" . round($avgTime, 4) . "ms per send)\n";
    }

    public function testCompressionPerformance(): void
    {
        $html = str_repeat(
            "<p>Test content for compression benchmark.</p>",
            1000,
        );

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [],
            "compress" => true,
        ];

        $iterations = 1000;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->sender->reset();
            $this->sender->send($input);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $avgTime = $duration / $iterations;

        // With compression should still be reasonably fast
        $this->assertLessThan(
            5.0,
            $avgTime,
            "Average time with compression: {$avgTime}ms (should be <5ms)",
        );

        echo "✓ Compression: {$iterations} sends in " .
            round($duration, 2) .
            "ms ";
        echo "(" . round($avgTime, 4) . "ms per send)\n";
    }

    // ========================================
    // Integration-Style Tests
    // ========================================

    public function testCompleteResponseFlow(): void
    {
        $html = str_repeat(
            "<html><body>Complete test content.</body></html>",
            100,
        );

        $input = [
            "html" => $html,
            "status_code" => 200,
            "headers" => [
                "Content-Type" => "text/html; charset=utf-8",
                "Cache-Control" => "public, max-age=3600",
                "X-Cache" => "HIT",
                "X-Cache-Key" => "abc123",
            ],
            "compress" => true,
        ];

        $result = $this->sender->send($input);

        // Verify all aspects of the response
        $this->assertTrue($result["sent"]);
        $this->assertTrue($result["compressed"]);
        $this->assertGreaterThan(0, $result["bytes_sent"]);
        $this->assertLessThan(strlen($html), $result["bytes_sent"]);

        // Verify status code
        $this->assertEquals(200, $this->sender->sentStatusCode);

        // Verify headers
        $this->assertContains(
            "Content-Type: text/html; charset=utf-8",
            $this->sender->sentHeaders,
        );
        $this->assertContains(
            "Cache-Control: public, max-age=3600",
            $this->sender->sentHeaders,
        );
        $this->assertContains("X-Cache: HIT", $this->sender->sentHeaders);
        $this->assertContains(
            "X-Cache-Key: abc123",
            $this->sender->sentHeaders,
        );
        $this->assertContains(
            "Content-Encoding: gzip",
            $this->sender->sentHeaders,
        );
        $this->assertContains(
            "Vary: Accept-Encoding",
            $this->sender->sentHeaders,
        );

        // Verify content can be decompressed
        $decompressed = gzdecode($this->sender->sentOutput);
        $this->assertEquals($html, $decompressed);
    }

    public function testPrepareMatchesSendOutput(): void
    {
        $html = str_repeat("<p>Test matching</p>", 100);

        $input = [
            "html" => $html,
            "status_code" => 201,
            "headers" => ["X-Test" => "Match"],
            "compress" => true,
        ];

        // Prepare without sending
        $prepared = $this->sender->prepare($input);

        // Now send
        $this->sender->reset();
        $result = $this->sender->send($input);

        // Results should match
        $this->assertEquals(
            $prepared["status_code"],
            $this->sender->sentStatusCode,
        );
        $this->assertEquals($prepared["compressed"], $result["compressed"]);
        $this->assertEquals($prepared["bytes_sent"], $result["bytes_sent"]);
        $this->assertEquals($prepared["content"], $this->sender->sentOutput);
    }
}
