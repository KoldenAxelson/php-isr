<?php

declare(strict_types=1);

/**
 * CachePurger
 *
 * Deletes cache entries by key, pattern, or purges entire cache.
 * Designed for simplicity and maintainability while meeting performance requirements.
 */
class CachePurger
{
    /**
     * @var string Base directory for cache storage
     */
    private string $cacheDir;

    /**
     * @var array Statistics from last purge operation
     */
    private array $lastStats = [];

    /**
     * Constructor
     *
     * @param string $cacheDir Base directory for cache files
     */
    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, "/");
    }

    /**
     * Purge cache entries based on input criteria
     *
     * Supports three modes:
     * 1. Individual keys: ['keys' => ['abc123', 'def456']]
     * 2. Pattern matching: ['pattern' => '/blog/*']
     * 3. Full purge: ['purge_all' => true]
     *
     * @param array $input Purge criteria
     * @return array Result with purged_count, keys_purged, and errors
     */
    public function purge(array $input): array
    {
        $this->lastStats = [
            "purged_count" => 0,
            "keys_purged" => [],
            "errors" => [],
        ];

        // Determine purge mode and execute
        if (isset($input["purge_all"]) && $input["purge_all"] === true) {
            $this->purgeAll();
        } elseif (isset($input["pattern"])) {
            $this->purgeByPattern($input["pattern"]);
        } elseif (isset($input["keys"])) {
            $this->purgeByKeys($input["keys"]);
        } else {
            $this->lastStats["errors"][] =
                "Invalid input: must specify keys, pattern, or purge_all";
        }

        return $this->lastStats;
    }

    /**
     * Purge specific cache keys
     *
     * @param array $keys Array of cache keys to purge
     */
    private function purgeByKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                $this->lastStats["errors"][] =
                    "Invalid key type: expected string, got " . gettype($key);
                continue;
            }

            $filePath = $this->getFilePath($key);

            if (file_exists($filePath)) {
                if ($this->deleteFile($filePath)) {
                    $this->lastStats["purged_count"]++;
                    $this->lastStats["keys_purged"][] = $key;
                } else {
                    $this->lastStats[
                        "errors"
                    ][] = "Failed to delete key: {$key}";
                }
            }
            // Silently skip non-existent keys (not an error)
        }
    }

    /**
     * Purge cache entries matching a pattern
     *
     * Reads metadata from cache files and matches pattern against the URL.
     * Requires cache files to have metadata['url'] field.
     *
     * @param string $pattern Pattern to match against URLs (supports * wildcard)
     */
    private function purgeByPattern(string $pattern): void
    {
        // Convert pattern to regex
        $regex = $this->patternToRegex($pattern);

        // Get all cache files
        $files = $this->getAllCacheFiles();

        foreach ($files as $filePath) {
            // Extract key from filename
            $filename = basename($filePath);
            $key = substr($filename, 0, -6); // Remove '.cache'

            // Read metadata from cache file
            $metadata = $this->readMetadata($filePath);

            // Skip files without URL in metadata
            if ($metadata === null || !isset($metadata["url"])) {
                continue;
            }

            $url = $metadata["url"];

            // Check if URL matches pattern
            if (preg_match($regex, $url)) {
                if ($this->deleteFile($filePath)) {
                    $this->lastStats["purged_count"]++;
                    $this->lastStats["keys_purged"][] = $key;
                } else {
                    $this->lastStats[
                        "errors"
                    ][] = "Failed to delete key: {$key}";
                }
            }
        }
    }

    /**
     * Purge all cache entries
     */
    private function purgeAll(): void
    {
        $files = $this->getAllCacheFiles();

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $key = substr($filename, 0, -6); // Remove '.cache'

            if ($this->deleteFile($filePath)) {
                $this->lastStats["purged_count"]++;
                $this->lastStats["keys_purged"][] = $key;
            } else {
                $this->lastStats["errors"][] = "Failed to delete key: {$key}";
            }
        }
    }

    /**
     * Convert wildcard pattern to regex
     *
     * @param string $pattern Wildcard pattern
     * @return string Regular expression pattern
     */
    private function patternToRegex(string $pattern): string
    {
        // Escape special regex characters except *
        $escaped = preg_quote($pattern, "/");

        // Replace escaped \* with .* for wildcard matching
        $regex = str_replace("\\*", ".*", $escaped);

        // Anchor to match entire string
        return "/^" . $regex . "$/";
    }

    /**
     * Get all cache files from cache directory
     *
     * @return array Array of file paths
     */
    private function getAllCacheFiles(): array
    {
        if (!is_dir($this->cacheDir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->cacheDir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === "cache") {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Read metadata from a cache file
     *
     * @param string $filePath Path to cache file
     * @return array|null Metadata array or null if not found/invalid
     */
    private function readMetadata(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $json = @file_get_contents($filePath);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        return $data["metadata"] ?? null;
    }

    /**
     * Get file path for a cache key
     *
     * Uses first 2 characters for sharding to avoid directory size issues
     *
     * @param string $key Cache key
     * @return string Full file path
     */
    private function getFilePath(string $key): string
    {
        // Use first 2 characters for directory sharding
        $shard = substr($key, 0, 2);
        return $this->cacheDir . "/" . $shard . "/" . $key . ".cache";
    }

    /**
     * Delete a file safely
     *
     * @param string $filePath Path to file
     * @return bool True if deleted successfully
     */
    private function deleteFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        try {
            return @unlink($filePath);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get statistics from last purge operation
     *
     * @return array Statistics array
     */
    public function getLastStats(): array
    {
        return $this->lastStats;
    }

    /**
     * Count total cache entries
     *
     * Useful for monitoring cache size
     *
     * @return int Number of cache entries
     */
    public function count(): int
    {
        return count($this->getAllCacheFiles());
    }

    /**
     * Verify cache directory is writable
     *
     * @return bool True if cache directory is writable
     */
    public function isWritable(): bool
    {
        return is_dir($this->cacheDir) && is_writable($this->cacheDir);
    }

    /**
     * Get cache directory path
     *
     * @return string Cache directory path
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }
}
