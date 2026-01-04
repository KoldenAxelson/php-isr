<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/RequestClassifier.php";

/**
 * RequestClassifierTest
 *
 * Comprehensive test suite for the RequestClassifier class
 */
class RequestClassifierTest extends TestCase
{
    private RequestClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RequestClassifier();
    }

    /**
     * Test basic cacheable GET request
     */
    public function testCacheableGetRequest(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/blog/post-123",
            "cookies" => [],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertTrue($result["cacheable"]);
        $this->assertEquals("cacheable", $result["rule_triggered"]);
        $this->assertStringContainsString(
            "meets all caching criteria",
            $result["reason"],
        );
    }

    /**
     * Test GET request with tracking parameters (should still be cacheable)
     */
    public function testCacheableWithTrackingParams(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/blog/post-123",
            "cookies" => [],
            "query" => ["utm_source" => "google", "utm_campaign" => "summer"],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertTrue($result["cacheable"]);
        $this->assertArrayHasKey("cache_key_components", $result);
        // Tracking params should be filtered out
        $this->assertEmpty($result["cache_key_components"]["query"]);
    }

    /**
     * Test non-cacheable POST request
     */
    public function testNonCacheablePostRequest(): void
    {
        $request = [
            "method" => "POST",
            "url" => "/contact-form",
            "cookies" => [],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("non_cacheable_method", $result["rule_triggered"]);
        $this->assertStringContainsString("POST", $result["reason"]);
    }

    /**
     * Test non-cacheable request with WordPress login cookie
     */
    public function testNonCacheableLoggedInWordPress(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/blog/post-123",
            "cookies" => ["wordpress_logged_in_abc123" => "user_data"],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("logged_in_user", $result["rule_triggered"]);
        $this->assertStringContainsString(
            "wordpress_logged_in_abc123",
            $result["reason"],
        );
    }

    /**
     * Test non-cacheable request with session cookie
     */
    public function testNonCacheableWithSessionCookie(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/dashboard",
            "cookies" => ["PHPSESSID" => "abc123xyz789"],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("logged_in_user", $result["rule_triggered"]);
    }

    /**
     * Test non-cacheable request with authorization header
     */
    public function testNonCacheableWithAuthorizationHeader(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/api/user-data",
            "cookies" => [],
            "query" => [],
            "headers" => ["Authorization" => "Bearer token123"],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("authorization_header", $result["rule_triggered"]);
    }

    /**
     * Test non-cacheable AJAX request
     */
    public function testNonCacheableAjaxRequest(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/api/fetch-data",
            "cookies" => [],
            "query" => [],
            "headers" => ["X-Requested-With" => "XMLHttpRequest"],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("ajax_request", $result["rule_triggered"]);
    }

    /**
     * Test non-cacheable request with preview parameter
     */
    public function testNonCacheablePreviewMode(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/blog/post-123",
            "cookies" => [],
            "query" => ["preview" => "true"],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("dynamic_query_param", $result["rule_triggered"]);
        $this->assertStringContainsString("preview", $result["reason"]);
    }

    /**
     * Test non-cacheable request with nocache parameter
     */
    public function testNonCacheableWithNoCacheParam(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/page",
            "cookies" => [],
            "query" => ["nocache" => "1"],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("dynamic_query_param", $result["rule_triggered"]);
    }

    /**
     * Test HEAD request (should be cacheable)
     */
    public function testCacheableHeadRequest(): void
    {
        $request = [
            "method" => "HEAD",
            "url" => "/blog/post-123",
            "cookies" => [],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertTrue($result["cacheable"]);
    }

    /**
     * Test PUT request (should not be cacheable)
     */
    public function testNonCacheablePutRequest(): void
    {
        $request = [
            "method" => "PUT",
            "url" => "/api/update-resource",
            "cookies" => [],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("non_cacheable_method", $result["rule_triggered"]);
    }

    /**
     * Test batch processing
     */
    public function testBatchClassification(): void
    {
        $requests = [
            [
                "method" => "GET",
                "url" => "/page1",
                "cookies" => [],
                "query" => [],
                "headers" => [],
            ],
            [
                "method" => "POST",
                "url" => "/form",
                "cookies" => [],
                "query" => [],
                "headers" => [],
            ],
            [
                "method" => "GET",
                "url" => "/page2",
                "cookies" => ["PHPSESSID" => "xyz"],
                "query" => [],
                "headers" => [],
            ],
        ];

        $results = $this->classifier->classifyBatch($requests);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]["cacheable"]);
        $this->assertFalse($results[1]["cacheable"]);
        $this->assertFalse($results[2]["cacheable"]);
    }

    /**
     * Test request with multiple query parameters
     */
    public function testMixedQueryParameters(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/search",
            "cookies" => [],
            "query" => [
                "q" => "search term",
                "page" => "2",
                "utm_source" => "google", // Should be filtered
            ],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertTrue($result["cacheable"]);
        $this->assertArrayHasKey("cache_key_components", $result);
        $this->assertArrayHasKey("q", $result["cache_key_components"]["query"]);
        $this->assertArrayNotHasKey(
            "utm_source",
            $result["cache_key_components"]["query"],
        );
    }

    /**
     * PERFORMANCE BENCHMARK: Test processing 10,000 requests in under 100ms
     */
    public function testPerformanceBenchmark(): void
    {
        // Generate 10,000 test requests
        $requests = [];
        for ($i = 0; $i < 10000; $i++) {
            // Mix of cacheable and non-cacheable requests
            $requests[] = [
                "method" => $i % 4 === 0 ? "POST" : "GET",
                "url" => "/page-" . $i,
                "cookies" => $i % 3 === 0 ? ["session" => "xyz"] : [],
                "query" =>
                    $i % 5 === 0
                        ? ["preview" => "true"]
                        : ["utm_source" => "test"],
                "headers" => [],
            ];
        }

        // Start timing
        $startTime = microtime(true);

        // Process all requests
        $results = $this->classifier->classifyBatch($requests);

        // End timing
        $endTime = microtime(true);
        $elapsedMs = ($endTime - $startTime) * 1000;

        // Assert results
        $this->assertCount(10000, $results);
        $this->assertLessThan(
            100,
            $elapsedMs,
            sprintf(
                "Processing took %.2fms, should be under 100ms",
                $elapsedMs,
            ),
        );

        echo sprintf(
            "\n✓ Performance: 10,000 requests processed in %.2fms\n",
            $elapsedMs,
        );
    }

    /**
     * Test edge case: empty request
     */
    public function testEmptyRequest(): void
    {
        $request = [];

        $result = $this->classifier->classify($request);

        // Should default to GET and be cacheable
        $this->assertTrue($result["cacheable"]);
    }

    /**
     * Test case insensitive HTTP method
     */
    public function testCaseInsensitiveMethod(): void
    {
        $request = [
            "method" => "get",
            "url" => "/page",
            "cookies" => [],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertTrue($result["cacheable"]);
    }

    /**
     * Test request with comment author cookie (WordPress)
     */
    public function testCommentAuthorCookie(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/blog/post",
            "cookies" => ["comment_author_123abc" => "John Doe"],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("logged_in_user", $result["rule_triggered"]);
    }

    /**
     * Test all required output fields are present
     */
    public function testOutputStructure(): void
    {
        $request = [
            "method" => "GET",
            "url" => "/test",
            "cookies" => [],
            "query" => [],
            "headers" => [],
        ];

        $result = $this->classifier->classify($request);

        $this->assertArrayHasKey("cacheable", $result);
        $this->assertArrayHasKey("reason", $result);
        $this->assertArrayHasKey("rule_triggered", $result);
        $this->assertIsBool($result["cacheable"]);
        $this->assertIsString($result["reason"]);
        $this->assertIsString($result["rule_triggered"]);
    }

    /**
     * Test adding custom login cookie pattern
     */
    public function testAddLoginCookiePattern(): void
    {
        $classifier = new RequestClassifier();

        // Add custom pattern
        $classifier->addLoginCookiePattern("myapp_user_");

        // Request with custom cookie should not be cacheable
        $request = [
            "method" => "GET",
            "url" => "/page",
            "cookies" => ["myapp_user_123" => "john"],
            "query" => [],
            "headers" => [],
        ];

        $result = $classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("logged_in_user", $result["rule_triggered"]);
        $this->assertStringContainsString("myapp_user_123", $result["reason"]);
    }

    /**
     * Test adding duplicate login cookie pattern (should not duplicate)
     */
    public function testAddDuplicateLoginCookiePattern(): void
    {
        $classifier = new RequestClassifier();

        // Add same pattern twice
        $classifier->addLoginCookiePattern("custom_session_");
        $classifier->addLoginCookiePattern("custom_session_");

        // Should still work correctly (no duplicates)
        $request = [
            "method" => "GET",
            "url" => "/page",
            "cookies" => ["custom_session_abc" => "xyz"],
            "query" => [],
            "headers" => [],
        ];

        $result = $classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("logged_in_user", $result["rule_triggered"]);
    }

    /**
     * Test adding custom dynamic query parameter
     */
    public function testAddDynamicQueryParam(): void
    {
        $classifier = new RequestClassifier();

        // Add custom parameter
        $classifier->addDynamicQueryParam("personalized");

        // Request with custom param should not be cacheable
        $request = [
            "method" => "GET",
            "url" => "/page",
            "cookies" => [],
            "query" => ["personalized" => "true"],
            "headers" => [],
        ];

        $result = $classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("dynamic_query_param", $result["rule_triggered"]);
        $this->assertStringContainsString("personalized", $result["reason"]);
    }

    /**
     * Test adding duplicate dynamic query parameter (should not duplicate)
     */
    public function testAddDuplicateDynamicQueryParam(): void
    {
        $classifier = new RequestClassifier();

        // Add same param twice
        $classifier->addDynamicQueryParam("custom_mode");
        $classifier->addDynamicQueryParam("custom_mode");

        // Should still work correctly (no duplicates)
        $request = [
            "method" => "GET",
            "url" => "/page",
            "cookies" => [],
            "query" => ["custom_mode" => "1"],
            "headers" => [],
        ];

        $result = $classifier->classify($request);

        $this->assertFalse($result["cacheable"]);
        $this->assertEquals("dynamic_query_param", $result["rule_triggered"]);
    }
}
