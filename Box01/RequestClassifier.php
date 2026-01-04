<?php

declare(strict_types=1);

/**
 * RequestClassifier
 *
 * High-performance HTTP request classifier for cache decision-making.
 * Determines if a request should be cached based on various rules.
 */
class RequestClassifier
{
    /**
     * List of HTTP methods that are cacheable
     */
    private const CACHEABLE_METHODS = ["GET", "HEAD"];

    /**
     * Cookie patterns that indicate a logged-in user
     */
    private array $loginCookiePatterns = [
        "wordpress_logged_in_",
        "wp-settings-",
        "wp-settings-time-",
        "comment_author_",
        "session",
        "PHPSESSID",
        "laravel_session",
        "user_token",
        "auth_token",
        "_session_id",
    ];

    /**
     * Query parameters that typically indicate dynamic/personalized content
     */
    private array $dynamicQueryParams = [
        "nocache",
        "no-cache",
        "preview",
        "draft",
        "debug",
        "edit",
        "admin",
    ];

    /**
     * Headers that indicate non-cacheable requests
     */
    private const NON_CACHEABLE_HEADERS = [
        "Authorization",
        "X-Requested-With" => "XMLHttpRequest", // AJAX requests
    ];

    /**
     * Classify a request to determine if it should be cached
     *
     * @param array $request The request data containing method, url, cookies, query, headers
     * @return array Decision object with cacheable, reason, and rule_triggered
     */
    public function classify(array $request): array
    {
        // Rule 1: Check HTTP method
        $method = strtoupper($request["method"] ?? "GET");
        if (!in_array($method, self::CACHEABLE_METHODS, true)) {
            return $this->notCacheable(
                "HTTP method '$method' is not cacheable",
                "non_cacheable_method",
            );
        }

        // Rule 2: Check for logged-in user via cookies
        $cookies = $request["cookies"] ?? [];
        foreach ($cookies as $cookieName => $cookieValue) {
            foreach ($this->loginCookiePatterns as $pattern) {
                if (stripos($cookieName, $pattern) === 0) {
                    return $this->notCacheable(
                        "User is logged in (cookie: $cookieName)",
                        "logged_in_user",
                    );
                }
            }
        }

        // Rule 3: Check for authorization headers
        $headers = $request["headers"] ?? [];
        foreach (self::NON_CACHEABLE_HEADERS as $headerName => $headerValue) {
            if (is_int($headerName)) {
                // Simple header name check
                if (isset($headers[$headerValue])) {
                    return $this->notCacheable(
                        "Request contains '$headerValue' header",
                        "authorization_header",
                    );
                }
            } else {
                // Header name and value check
                if (
                    isset($headers[$headerName]) &&
                    $headers[$headerName] === $headerValue
                ) {
                    return $this->notCacheable(
                        "Request is an AJAX request",
                        "ajax_request",
                    );
                }
            }
        }

        // Rule 4: Check for dynamic query parameters
        $query = $request["query"] ?? [];
        foreach ($this->dynamicQueryParams as $param) {
            if (isset($query[$param])) {
                return $this->notCacheable(
                    "Query parameter '$param' indicates dynamic content",
                    "dynamic_query_param",
                );
            }
        }

        // Rule 5: Check for POST data (if method is somehow GET but has post data)
        if (isset($request["post"]) && !empty($request["post"])) {
            return $this->notCacheable(
                "Request contains POST data",
                "has_post_data",
            );
        }

        // Rule 6: Filter out marketing/tracking query parameters for cache key
        $filteredQuery = $this->filterTrackingParams($query);
        $cacheKeyInfo = !empty($filteredQuery)
            ? " (with query params: " .
                implode(", ", array_keys($filteredQuery)) .
                ")"
            : "";

        // All rules passed - request is cacheable
        return [
            "cacheable" => true,
            "reason" => "Request meets all caching criteria" . $cacheKeyInfo,
            "rule_triggered" => "cacheable",
            "cache_key_components" => [
                "method" => $method,
                "url" => $request["url"] ?? "",
                "query" => $filteredQuery,
            ],
        ];
    }

    /**
     * Process multiple requests in batch
     *
     * @param array $requests Array of request arrays
     * @return array Array of classification results
     */
    public function classifyBatch(array $requests): array
    {
        $results = [];
        foreach ($requests as $index => $request) {
            $results[$index] = $this->classify($request);
        }
        return $results;
    }

    /**
     * Filter out common tracking/marketing query parameters
     * These don't affect content but would create unnecessary cache variations
     *
     * @param array $query Query parameters
     * @return array Filtered query parameters
     */
    private function filterTrackingParams(array $query): array
    {
        $trackingParams = [
            "utm_source",
            "utm_medium",
            "utm_campaign",
            "utm_term",
            "utm_content",
            "gclid",
            "fbclid",
            "msclkid",
            "_ga",
            "mc_cid",
            "mc_eid",
        ];

        return array_diff_key($query, array_flip($trackingParams));
    }

    /**
     * Create a non-cacheable decision response
     *
     * @param string $reason Human-readable reason
     * @param string $ruleTriggered Rule identifier
     * @return array Decision object
     */
    private function notCacheable(string $reason, string $ruleTriggered): array
    {
        return [
            "cacheable" => false,
            "reason" => $reason,
            "rule_triggered" => $ruleTriggered,
        ];
    }

    /**
     * Add custom login cookie pattern
     *
     * @param string $pattern Cookie name pattern
     * @return void
     */
    public function addLoginCookiePattern(string $pattern): void
    {
        if (!in_array($pattern, $this->loginCookiePatterns, true)) {
            $this->loginCookiePatterns[] = $pattern;
        }
    }

    /**
     * Add custom dynamic query parameter
     *
     * @param string $param Query parameter name
     * @return void
     */
    public function addDynamicQueryParam(string $param): void
    {
        if (!in_array($param, $this->dynamicQueryParams, true)) {
            $this->dynamicQueryParams[] = $param;
        }
    }
}
