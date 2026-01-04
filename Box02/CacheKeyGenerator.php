<?php

declare(strict_types=1);

/**
 * CacheKeyGenerator
 *
 * Generates deterministic, collision-resistant cache keys from URLs and context variants.
 * Designed for simplicity and maintainability while meeting performance requirements.
 */
class CacheKeyGenerator
{
    /**
     * Generate a cache key from URL and variants
     *
     * @param array $input Input with 'url' and optional 'variants'
     * @return string MD5 hash (32 characters)
     */
    public function generate(array $input): string
    {
        $url = $input["url"] ?? "";
        $variants = $input["variants"] ?? [];

        // Normalize URL for consistency
        $normalizedUrl = $this->normalizeUrl($url);

        // Sort variants for deterministic ordering
        ksort($variants);
        $this->normalizeVariantValues($variants);

        // Generate hash from normalized data
        $data = [
            "url" => $normalizedUrl,
            "variants" => $variants,
        ];

        return md5(json_encode($data));
    }

    /**
     * Generate multiple cache keys in batch
     *
     * @param array $inputs Array of input arrays
     * @return array Array of generated cache keys
     */
    public function generateBatch(array $inputs): array
    {
        $keys = [];
        foreach ($inputs as $index => $input) {
            $keys[$index] = $this->generate($input);
        }
        return $keys;
    }

    /**
     * Normalize URL to ensure consistent key generation
     *
     * Handles:
     * - Trailing slashes (removed, except for root)
     * - Query parameter ordering (alphabetical)
     * - Case insensitivity (protocol and host)
     * - Multiple slashes (collapsed to single)
     *
     * @param string $url The URL to normalize
     * @return string Normalized URL string
     */
    private function normalizeUrl(string $url): string
    {
        if (empty($url)) {
            return "/";
        }

        // Fast path for simple paths (most common case)
        // Avoids expensive parse_url() when not needed
        if (
            $url[0] === "/" &&
            strpos($url, "?") === false &&
            strpos($url, "://") === false
        ) {
            $normalized = $this->normalizePath($url);
            return $normalized;
        }

        // Full URL parsing for complex cases
        $parsed = parse_url($url);
        if ($parsed === false) {
            // Invalid URL, treat as path
            return $this->normalizePath($url);
        }

        $parts = [];

        // Protocol (lowercase)
        if (isset($parsed["scheme"])) {
            $parts[] = strtolower($parsed["scheme"]) . "://";
        }

        // Host (lowercase)
        if (isset($parsed["host"])) {
            $parts[] = strtolower($parsed["host"]);
        }

        // Port (if non-standard)
        if (isset($parsed["port"])) {
            $parts[] = ":" . $parsed["port"];
        }

        // Path (normalized)
        $path = $parsed["path"] ?? "/";
        $parts[] = $this->normalizePath($path);

        // Query string (sorted parameters)
        if (isset($parsed["query"])) {
            parse_str($parsed["query"], $query);
            ksort($query);
            $parts[] = "?" . http_build_query($query);
        }

        return implode("", $parts);
    }

    /**
     * Normalize a path component
     *
     * @param string $path The path to normalize
     * @return string Normalized path
     */
    private function normalizePath(string $path): string
    {
        // Remove duplicate slashes
        $path = preg_replace("#/+#", "/", $path);

        // Remove trailing slash (except for root)
        if ($path !== "/" && substr($path, -1) === "/") {
            $path = substr($path, 0, -1);
        }

        // Ensure leading slash
        if ($path === "" || $path[0] !== "/") {
            $path = "/" . $path;
        }

        return $path;
    }

    /**
     * Recursively normalize and sort variant values for deterministic ordering
     *
     * @param array<mixed> $array Array to normalize (modified in place)
     */
    private function normalizeVariantValues(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_string($value)) {
                // Normalize strings: lowercase and trim whitespace
                $value = strtolower(trim($value));
            } elseif (is_array($value)) {
                // Recursively normalize nested arrays
                ksort($value);
                $this->normalizeVariantValues($value);
            }
            // Preserve other types (bool, int, float) as-is
        }
    }

    /**
     * Verify two inputs generate the same cache key
     *
     * Useful for testing determinism
     *
     * @param array $input1 First input
     * @param array $input2 Second input
     * @return bool True if keys match
     */
    public function verifyDeterminism(array $input1, array $input2): bool
    {
        return $this->generate($input1) === $this->generate($input2);
    }
}
