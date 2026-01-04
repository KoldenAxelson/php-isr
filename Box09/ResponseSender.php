<?php

declare(strict_types=1);

/**
 * ResponseSender
 *
 * Sends HTTP responses to the browser with appropriate headers and optional compression.
 * Supports both simple array input and PSR-7 ResponseInterface.
 * Designed for simplicity and performance while handling edge cases gracefully.
 */
class ResponseSender
{
    /**
     * Send an HTTP response to the browser
     *
     * @param array $input Response configuration
     * @return array Result metadata
     */
    public function send(array $input): array
    {
        $prepared = $this->prepareResponse(
            $input["html"] ?? "",
            $input["status_code"] ?? 200,
            $input["headers"] ?? [],
            $input["compress"] ?? false,
        );

        return $this->sendPrepared($prepared);
    }

    /**
     * Send a PSR-7 ResponseInterface
     *
     * @param object $response PSR-7 ResponseInterface object
     * @param bool $compress Enable compression
     * @return array Result metadata
     */
    public function sendPsr7($response, bool $compress = false): array
    {
        // Validate PSR-7 interface
        if (
            !method_exists($response, "getStatusCode") ||
            !method_exists($response, "getHeaders") ||
            !method_exists($response, "getBody")
        ) {
            throw new InvalidArgumentException(
                "Response must implement PSR-7 ResponseInterface",
            );
        }

        // Extract data from PSR-7 response
        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders();
        $body = (string) $response->getBody();

        // Flatten multi-value headers
        $flatHeaders = [];
        foreach ($headers as $name => $values) {
            $flatHeaders[$name] = is_array($values)
                ? implode(", ", $values)
                : $values;
        }

        $prepared = $this->prepareResponse(
            $body,
            $statusCode,
            $flatHeaders,
            $compress,
        );

        return $this->sendPrepared($prepared);
    }

    /**
     * Prepare a response without sending (useful for testing)
     *
     * @param array $input Response configuration
     * @return array Prepared response data
     */
    public function prepare(array $input): array
    {
        return $this->prepareResponse(
            $input["html"] ?? "",
            $input["status_code"] ?? 200,
            $input["headers"] ?? [],
            $input["compress"] ?? false,
        );
    }

    /**
     * Prepare response data (shared logic for send and prepare)
     *
     * @param string $content Content to send
     * @param int $statusCode HTTP status code
     * @param array $headers Custom headers
     * @param bool $compress Enable compression
     * @return array Prepared response data
     */
    private function prepareResponse(
        string $content,
        int $statusCode,
        array $headers,
        bool $compress,
    ): array {
        // Validate status code
        if (!$this->isValidStatusCode($statusCode)) {
            throw new InvalidArgumentException(
                "Invalid status code: {$statusCode}",
            );
        }

        // Prepare content (with optional compression)
        $compressed = false;

        if ($compress && $this->shouldCompress($content)) {
            $compressed = $this->compressContent($content);
            if ($compressed) {
                $headers["Content-Encoding"] = "gzip";
                $headers["Vary"] = "Accept-Encoding";
            }
        }

        // Calculate content length
        $bytesSent = strlen($content);
        $headers["Content-Length"] = (string) $bytesSent;

        return [
            "status_code" => $statusCode,
            "headers" => $headers,
            "content" => $content,
            "bytes_sent" => $bytesSent,
            "compressed" => $compressed,
        ];
    }

    /**
     * Send a prepared response
     *
     * @param array $prepared Prepared response data
     * @return array Result metadata
     */
    private function sendPrepared(array $prepared): array
    {
        // Check if headers can be sent
        if ($this->headersSent()) {
            throw new RuntimeException("Headers already sent");
        }

        // Send status code
        $this->sendStatusCode($prepared["status_code"]);

        // Send headers
        foreach ($prepared["headers"] as $name => $value) {
            $this->sendHeader("{$name}: {$value}");
        }

        // Send content
        $this->sendOutput($prepared["content"]);

        return [
            "sent" => true,
            "bytes_sent" => $prepared["bytes_sent"],
            "compressed" => $prepared["compressed"],
        ];
    }

    /**
     * Check if a status code is valid
     *
     * @param int $code HTTP status code
     * @return bool True if valid
     */
    private function isValidStatusCode(int $code): bool
    {
        // Valid HTTP status codes are 100-599
        return $code >= 100 && $code <= 599;
    }

    /**
     * Determine if content should be compressed
     *
     * Checks:
     * 1. Content size (must be >1KB)
     * 2. Client Accept-Encoding header (must support gzip)
     *
     * @param string $content Content to check
     * @return bool True if content should be compressed
     */
    private function shouldCompress(string $content): bool
    {
        // Don't compress if content is too small (overhead not worth it)
        $minSize = 1024; // 1KB minimum
        if (strlen($content) < $minSize) {
            return false;
        }

        // Check if client accepts gzip encoding (case-insensitive per HTTP spec)
        $acceptEncoding = $this->getAcceptEncoding();
        if (stripos($acceptEncoding, "gzip") === false) {
            return false;
        }

        return true;
    }

    /**
     * Get the Accept-Encoding header value
     *
     * Wrapper for $_SERVER to allow testing
     *
     * @return string Accept-Encoding header value
     */
    protected function getAcceptEncoding(): string
    {
        return $_SERVER["HTTP_ACCEPT_ENCODING"] ?? "";
    }

    /**
     * Compress content using gzip
     *
     * Modifies content in place and returns success flag
     *
     * @param string &$content Content to compress (modified in place)
     * @return bool True if compression succeeded
     */
    private function compressContent(string &$content): bool
    {
        $compressed = gzencode($content, 6); // Level 6 = good balance of speed/compression

        if ($compressed === false) {
            return false;
        }

        // Only use compressed version if it's actually smaller
        if (strlen($compressed) < strlen($content)) {
            $content = $compressed;
            return true;
        }

        return false;
    }

    /**
     * Check if headers have already been sent
     *
     * Wrapper for headers_sent() to allow testing
     *
     * @return bool True if headers sent
     */
    protected function headersSent(): bool
    {
        return headers_sent();
    }

    /**
     * Send HTTP status code
     *
     * Wrapper for http_response_code() to allow testing
     *
     * @param int $code Status code
     */
    protected function sendStatusCode(int $code): void
    {
        http_response_code($code);
    }

    /**
     * Send an HTTP header
     *
     * Wrapper for header() to allow testing
     *
     * @param string $header Header string
     */
    protected function sendHeader(string $header): void
    {
        header($header);
    }

    /**
     * Send output to browser
     *
     * Wrapper for echo to allow testing
     *
     * @param string $content Content to send
     */
    protected function sendOutput(string $content): void
    {
        echo $content;
    }

    /**
     * Get compression ratio for content
     *
     * Useful for testing compression effectiveness
     *
     * @param string $original Original content
     * @param string $compressed Compressed content
     * @return float Compression ratio (0.0 to 1.0, lower is better)
     */
    public function getCompressionRatio(
        string $original,
        string $compressed,
    ): float {
        $originalSize = strlen($original);
        if ($originalSize === 0) {
            return 1.0;
        }

        return strlen($compressed) / $originalSize;
    }
}
