<?php

declare(strict_types=1);

/**
 * FileCacheStore
 *
 * Simple, fast file-based cache for HTML and other content.
 * Handles TTL expiration, metadata storage, and concurrent access safely.
 */
class FileCacheStore
{
    private string $cacheDir;
    private int $defaultTtl;
    private bool $useSharding;

    /**
     * @param string $cacheDir Directory for cache files
     * @param int $defaultTtl Default TTL in seconds (0 = never expire)
     * @param bool $useSharding Use 2-level directory sharding for many files
     */
    public function __construct(
        string $cacheDir = "./cache",
        int $defaultTtl = 3600,
        bool $useSharding = false,
    ) {
        $this->cacheDir = rtrim($cacheDir, "/");
        $this->defaultTtl = $defaultTtl;
        $this->useSharding = $useSharding;

        $this->ensureDirectoryExists($this->cacheDir);
    }

    /**
     * Write content to cache
     *
     * @param string $key Cache key
     * @param string $content Content to cache (typically HTML)
     * @param int|null $ttl TTL in seconds (null uses default, 0 = never expire)
     * @param array $metadata Optional metadata to store with content
     * @return bool True on success
     */
    public function write(
        string $key,
        string $content,
        ?int $ttl = null,
        array $metadata = [],
    ): bool {
        $ttl = $ttl ?? $this->defaultTtl;
        $filepath = $this->getFilePath($key);

        // Ensure subdirectory exists (for sharding)
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            $this->ensureDirectoryExists($dir);
        }

        $data = [
            "content" => $content,
            "created_at" => time(),
            "ttl" => $ttl,
            "metadata" => $metadata,
        ];

        // Use atomic write with file locking for concurrent safety
        $tempFile = $filepath . ".tmp." . uniqid();
        $json = json_encode($data);

        if ($json === false) {
            return false;
        }

        // Write to temp file first
        if (file_put_contents($tempFile, $json, LOCK_EX) === false) {
            return false;
        }

        // Atomic rename (overwrites existing file safely)
        if (!rename($tempFile, $filepath)) {
            @unlink($tempFile);
            return false;
        }

        return true;
    }

    /**
     * Read content from cache
     *
     * @param string $key Cache key
     * @return array|null Array with content, created_at, ttl, metadata or null if not found/expired
     */
    public function read(string $key): ?array
    {
        $filepath = $this->getFilePath($key);

        if (!file_exists($filepath)) {
            return null;
        }

        // Use LOCK_SH for concurrent reads
        $handle = fopen($filepath, "r");
        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            return null;
        }

        $json = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if ($data === null || !is_array($data)) {
            return null;
        }

        // Check TTL expiration
        if ($this->isExpired($data)) {
            // Delete expired entry
            @unlink($filepath);
            return null;
        }

        return $data;
    }

    /**
     * Delete a cache entry
     *
     * @param string $key Cache key
     * @return bool True if deleted, false if not found
     */
    public function delete(string $key): bool
    {
        $filepath = $this->getFilePath($key);

        if (!file_exists($filepath)) {
            return false;
        }

        return @unlink($filepath);
    }

    /**
     * Check if cache entry exists and is valid
     *
     * @param string $key Cache key
     * @return bool True if exists and not expired
     */
    public function exists(string $key): bool
    {
        return $this->read($key) !== null;
    }

    /**
     * List all valid cache entries
     *
     * @param bool $includeContent Include content in results (slower)
     * @return array Array of cache entries with keys
     */
    public function list(bool $includeContent = false): array
    {
        $entries = [];
        $files = $this->getAllCacheFiles();

        foreach ($files as $filepath) {
            $key = $this->getKeyFromFilePath($filepath);

            if ($includeContent) {
                $data = $this->read($key);
                if ($data !== null) {
                    $entries[$key] = $data;
                }
            } else {
                // Quick check without loading content
                if ($this->exists($key)) {
                    $entries[] = $key;
                }
            }
        }

        return $entries;
    }

    /**
     * Clear all cache entries
     *
     * @return int Number of entries deleted
     */
    public function clear(): int
    {
        $count = 0;
        $files = $this->getAllCacheFiles();

        foreach ($files as $filepath) {
            if (@unlink($filepath)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove expired entries
     *
     * @return int Number of entries pruned
     */
    public function prune(): int
    {
        $count = 0;
        $files = $this->getAllCacheFiles();

        foreach ($files as $filepath) {
            $handle = @fopen($filepath, "r");
            if ($handle === false) {
                continue;
            }

            $json = stream_get_contents($handle);
            fclose($handle);

            if ($json === false) {
                continue;
            }

            $data = json_decode($json, true);
            if ($data !== null && $this->isExpired($data)) {
                if (@unlink($filepath)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics about cache usage
     */
    public function getStats(): array
    {
        $files = $this->getAllCacheFiles();
        $totalSize = 0;
        $validCount = 0;
        $expiredCount = 0;

        foreach ($files as $filepath) {
            $size = @filesize($filepath);
            if ($size !== false) {
                $totalSize += $size;
            }

            $handle = @fopen($filepath, "r");
            if ($handle === false) {
                continue;
            }

            $json = stream_get_contents($handle);
            fclose($handle);

            if ($json === false) {
                continue;
            }

            $data = json_decode($json, true);
            if ($data !== null) {
                if ($this->isExpired($data)) {
                    $expiredCount++;
                } else {
                    $validCount++;
                }
            }
        }

        return [
            "total_entries" => count($files),
            "valid_entries" => $validCount,
            "expired_entries" => $expiredCount,
            "total_size_bytes" => $totalSize,
            "total_size_mb" => round($totalSize / 1024 / 1024, 2),
        ];
    }

    /**
     * Batch write multiple entries
     *
     * @param array $entries Array of ['key' => ['content' => ..., 'ttl' => ..., 'metadata' => ...]]
     * @return array Array of results [key => bool success]
     */
    public function writeBatch(array $entries): array
    {
        $results = [];

        foreach ($entries as $key => $data) {
            $content = $data["content"] ?? "";
            $ttl = $data["ttl"] ?? null;
            $metadata = $data["metadata"] ?? [];

            $results[$key] = $this->write($key, $content, $ttl, $metadata);
        }

        return $results;
    }

    /**
     * Batch read multiple entries
     *
     * @param array $keys Array of cache keys
     * @return array Array of [key => data] (missing keys not included)
     */
    public function readBatch(array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $data = $this->read($key);
            if ($data !== null) {
                $results[$key] = $data;
            }
        }

        return $results;
    }

    /**
     * Get file path for a cache key
     *
     * @param string $key Cache key
     * @return string Full file path
     */
    private function getFilePath(string $key): string
    {
        // Sanitize key to prevent directory traversal
        $safeKey = preg_replace("/[^a-zA-Z0-9_-]/", "_", $key);

        if ($this->useSharding) {
            // Two-level sharding: first 2 chars of hash
            $hash = md5($key);
            $level1 = substr($hash, 0, 2);
            $level2 = substr($hash, 2, 2);
            return "{$this->cacheDir}/{$level1}/{$level2}/{$safeKey}.cache";
        }

        return "{$this->cacheDir}/{$safeKey}.cache";
    }

    /**
     * Get cache key from file path
     *
     * @param string $filepath File path
     * @return string Cache key
     */
    private function getKeyFromFilePath(string $filepath): string
    {
        $basename = basename($filepath, ".cache");
        return $basename;
    }

    /**
     * Get all cache files recursively
     *
     * @return array Array of file paths
     */
    private function getAllCacheFiles(): array
    {
        $files = [];
        $this->scanDirectory($this->cacheDir, $files);
        return $files;
    }

    /**
     * Recursively scan directory for cache files
     *
     * @param string $dir Directory to scan
     * @param array<string> $files Array to collect files (passed by reference)
     */
    private function scanDirectory(string $dir, array &$files): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }

            $path = $dir . "/" . $item;

            if (is_dir($path)) {
                $this->scanDirectory($path, $files);
            } elseif (is_file($path) && substr($item, -6) === ".cache") {
                $files[] = $path;
            }
        }
    }

    /**
     * Check if cache entry is expired
     *
     * @param array $data Cache entry data
     * @return bool True if expired
     */
    private function isExpired(array $data): bool
    {
        $ttl = $data["ttl"] ?? 0;

        // TTL of 0 means never expire
        if ($ttl === 0) {
            return false;
        }

        $createdAt = $data["created_at"] ?? 0;
        $expiresAt = $createdAt + $ttl;

        return time() > $expiresAt;
    }

    /**
     * Ensure directory exists with proper permissions
     *
     * @param string $dir Directory path
     * @return bool True on success
     */
    private function ensureDirectoryExists(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0755, true);
    }
}
