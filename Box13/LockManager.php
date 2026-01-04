<?php

declare(strict_types=1);

/**
 * LockManager
 *
 * Prevents multiple processes from regenerating the same URL simultaneously.
 * Uses file-based locking for simplicity and cross-process compatibility.
 * Designed for reliability and performance while maintaining simplicity.
 */
class LockManager
{
    private string $lockDir;
    private int $defaultTimeout;
    private array $activeLocks = [];

    /**
     * Create a new LockManager instance
     *
     * @param string $lockDir Directory to store lock files (must be writable)
     * @param int $defaultTimeout Default lock timeout in seconds
     */
    public function __construct(
        string $lockDir = "/tmp/locks",
        int $defaultTimeout = 30,
    ) {
        $this->lockDir = rtrim($lockDir, "/");
        $this->defaultTimeout = $defaultTimeout;

        // Create lock directory if it doesn't exist
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
    }

    /**
     * Process a lock operation (acquire or release)
     *
     * @param array $input Input with 'key', 'action', and optional 'timeout'
     * @return array Operation result with status information
     */
    public function process(array $input): array
    {
        $key = $input["key"] ?? "";
        $action = $input["action"] ?? "acquire";
        $timeout = $input["timeout"] ?? $this->defaultTimeout;

        if (empty($key)) {
            return [
                "locked" => false,
                "lock_id" => null,
                "expires_at" => null,
                "already_locked" => false,
                "error" => "Key is required",
            ];
        }

        if ($action === "acquire") {
            return $this->acquire($key, $timeout);
        } elseif ($action === "release") {
            return $this->release($key);
        } else {
            return [
                "locked" => false,
                "lock_id" => null,
                "expires_at" => null,
                "already_locked" => false,
                "error" => "Invalid action: " . $action,
            ];
        }
    }

    /**
     * Acquire a lock for a given key
     *
     * @param string $key Lock key (typically a cache key or URL identifier)
     * @param int|null $timeout Lock timeout in seconds
     * @return array Lock acquisition result
     */
    public function acquire(string $key, ?int $timeout = null): array
    {
        $timeout = $timeout ?? $this->defaultTimeout;
        $lockFile = $this->getLockFilePath($key);

        // Clean up expired locks first
        $this->cleanupExpiredLock($lockFile);

        // Check if already locked
        if (file_exists($lockFile)) {
            $lockData = $this->readLockFile($lockFile);

            if ($lockData && $lockData["expires_at"] > time()) {
                // Lock is still valid
                return [
                    "locked" => false,
                    "lock_id" => null,
                    "expires_at" => null,
                    "already_locked" => true,
                    "locked_by" => $lockData["lock_id"] ?? null,
                ];
            }
        }

        // Try to acquire the lock
        $lockId = $this->generateLockId();
        $expiresAt = time() + $timeout;

        $lockData = [
            "lock_id" => $lockId,
            "key" => $key,
            "expires_at" => $expiresAt,
            "acquired_at" => time(),
            "pid" => getmypid(),
        ];

        // Atomic lock acquisition using exclusive file creation
        $handle = @fopen($lockFile, "x");
        if ($handle === false) {
            // Lock file was created by another process between our check and now
            return [
                "locked" => false,
                "lock_id" => null,
                "expires_at" => null,
                "already_locked" => true,
            ];
        }

        // Write lock data and close
        fwrite($handle, json_encode($lockData));
        fclose($handle);

        // Track in memory for this instance
        $this->activeLocks[$key] = $lockId;

        return [
            "locked" => true,
            "lock_id" => $lockId,
            "expires_at" => $expiresAt,
            "already_locked" => false,
        ];
    }

    /**
     * Release a lock for a given key
     *
     * @param string $key Lock key to release
     * @return array Lock release result
     */
    public function release(string $key): array
    {
        $lockFile = $this->getLockFilePath($key);

        if (!file_exists($lockFile)) {
            return [
                "locked" => false,
                "lock_id" => null,
                "expires_at" => null,
                "already_locked" => false,
                "released" => false,
                "error" => "Lock does not exist",
            ];
        }

        // Read lock data to verify ownership (optional, but good practice)
        $lockData = $this->readLockFile($lockFile);

        // Remove lock file
        $released = @unlink($lockFile);

        // Remove from active locks
        unset($this->activeLocks[$key]);

        return [
            "locked" => false,
            "lock_id" => $lockData["lock_id"] ?? null,
            "expires_at" => null,
            "already_locked" => false,
            "released" => $released,
        ];
    }

    /**
     * Check if a key is currently locked
     *
     * @param string $key Lock key to check
     * @return bool True if locked
     */
    public function isLocked(string $key): bool
    {
        $lockFile = $this->getLockFilePath($key);

        if (!file_exists($lockFile)) {
            return false;
        }

        // Check if lock has expired
        $lockData = $this->readLockFile($lockFile);

        if (!$lockData || $lockData["expires_at"] <= time()) {
            // Lock expired, clean it up
            @unlink($lockFile);
            return false;
        }

        return true;
    }

    /**
     * Wait for a lock to become available, then acquire it
     *
     * @param string $key Lock key
     * @param int|null $timeout Lock timeout in seconds
     * @param int $maxWait Maximum time to wait in seconds (0 = don't wait)
     * @param int $retryInterval Milliseconds between retry attempts
     * @return array Lock acquisition result
     */
    public function acquireWithWait(
        string $key,
        ?int $timeout = null,
        int $maxWait = 5,
        int $retryInterval = 100,
    ): array {
        $timeout = $timeout ?? $this->defaultTimeout;
        $startTime = microtime(true);
        $maxWaitTime = $maxWait;

        while (true) {
            $result = $this->acquire($key, $timeout);

            if ($result["locked"]) {
                return $result;
            }

            // Check if we should keep waiting
            $elapsed = microtime(true) - $startTime;
            if ($maxWaitTime > 0 && $elapsed >= $maxWaitTime) {
                return [
                    "locked" => false,
                    "lock_id" => null,
                    "expires_at" => null,
                    "already_locked" => true,
                    "timeout_waiting" => true,
                ];
            }

            // Wait before retrying
            usleep($retryInterval * 1000);
        }
    }

    /**
     * Cleanup all expired locks in the lock directory
     *
     * @return int Number of locks cleaned up
     */
    public function cleanupExpiredLocks(): int
    {
        $cleaned = 0;
        $files = glob($this->lockDir . "/*.lock");

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if ($this->cleanupExpiredLock($file)) {
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Release all locks held by this instance
     *
     * Useful for cleanup in shutdown handlers
     *
     * @return int Number of locks released
     */
    public function releaseAll(): int
    {
        $released = 0;

        foreach (array_keys($this->activeLocks) as $key) {
            $result = $this->release($key);
            if ($result["released"] ?? false) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Get statistics about current locks
     *
     * @return array Lock statistics
     */
    public function getStats(): array
    {
        $files = glob($this->lockDir . "/*.lock");
        $totalLocks = $files ? count($files) : 0;
        $activeLocks = 0;
        $expiredLocks = 0;

        if ($files) {
            foreach ($files as $file) {
                $lockData = $this->readLockFile($file);
                if ($lockData) {
                    if ($lockData["expires_at"] > time()) {
                        $activeLocks++;
                    } else {
                        $expiredLocks++;
                    }
                }
            }
        }

        return [
            "total_locks" => $totalLocks,
            "active_locks" => $activeLocks,
            "expired_locks" => $expiredLocks,
            "instance_locks" => count($this->activeLocks),
        ];
    }

    /**
     * Get the lock file path for a given key
     *
     * @param string $key Lock key
     * @return string Full path to lock file
     */
    private function getLockFilePath(string $key): string
    {
        // Use MD5 to create a safe filename from any key
        $safeKey = md5($key);
        return $this->lockDir . "/" . $safeKey . ".lock";
    }

    /**
     * Generate a unique lock ID
     *
     * @return string Unique lock identifier
     */
    private function generateLockId(): string
    {
        return "lock_" . uniqid("", true) . "_" . getmypid();
    }

    /**
     * Read and parse a lock file
     *
     * @param string $lockFile Path to lock file
     * @return array|null Lock data or null if invalid
     */
    private function readLockFile(string $lockFile): ?array
    {
        $content = @file_get_contents($lockFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Cleanup a single expired lock file
     *
     * @param string $lockFile Path to lock file
     * @return bool True if lock was expired and cleaned up
     */
    private function cleanupExpiredLock(string $lockFile): bool
    {
        if (!file_exists($lockFile)) {
            return false;
        }

        $lockData = $this->readLockFile($lockFile);

        if ($lockData && $lockData["expires_at"] <= time()) {
            @unlink($lockFile);
            return true;
        }

        return false;
    }
}
