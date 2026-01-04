<?php

declare(strict_types=1);

/**
 * HealthMonitor
 *
 * Monitors system health by checking disk space, permissions, PHP version, and memory.
 * Designed for fast, reliable health checks in production environments.
 */
class HealthMonitor
{
    /**
     * Default minimum disk space (1GB in bytes)
     */
    private const DEFAULT_MIN_DISK_SPACE = 1073741824;

    /**
     * Default minimum PHP version
     */
    private const DEFAULT_MIN_PHP_VERSION = "7.4.0";

    /**
     * Default minimum available memory (64MB in bytes)
     */
    private const DEFAULT_MIN_MEMORY = 67108864;

    /**
     * Configuration options
     */
    private array $config;

    /**
     * Create a new HealthMonitor instance
     *
     * @param array $config Optional configuration overrides
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(
            [
                "min_disk_space" => self::DEFAULT_MIN_DISK_SPACE,
                "min_php_version" => self::DEFAULT_MIN_PHP_VERSION,
                "min_memory" => self::DEFAULT_MIN_MEMORY,
            ],
            $config,
        );
    }

    /**
     * Check system health
     *
     * @param array $input Input with 'cache_dir' and optional 'checks'
     * @return array Health status with overall health and individual check results
     */
    public function check(array $input): array
    {
        $cacheDir = $input["cache_dir"] ?? "";
        $requestedChecks = $input["checks"] ?? [
            "disk_space",
            "permissions",
            "php_version",
            "memory",
        ];

        $results = [];
        $allHealthy = true;

        // Run only requested checks
        foreach ($requestedChecks as $checkName) {
            $result = $this->runCheck($checkName, $cacheDir);
            $results[$checkName] = $result;

            if ($result["status"] !== "ok") {
                $allHealthy = false;
            }
        }

        return [
            "healthy" => $allHealthy,
            "checks" => $results,
        ];
    }

    /**
     * Run a specific health check
     *
     * @param string $checkName Name of the check to run
     * @param string $cacheDir Cache directory path
     * @return array Check result
     */
    private function runCheck(string $checkName, string $cacheDir): array
    {
        switch ($checkName) {
            case "disk_space":
                return $this->checkDiskSpace($cacheDir);

            case "permissions":
                return $this->checkPermissions($cacheDir);

            case "php_version":
                return $this->checkPhpVersion();

            case "memory":
                return $this->checkMemory();

            default:
                return [
                    "status" => "fail",
                    "error" => "Unknown check: {$checkName}",
                ];
        }
    }

    /**
     * Check available disk space
     *
     * @param string $path Directory path to check
     * @return array Check result with status and disk space information
     */
    private function checkDiskSpace(string $path): array
    {
        if (empty($path)) {
            return [
                "status" => "fail",
                "error" => "No path provided for disk space check",
            ];
        }

        // Use directory if it exists, otherwise use parent directory
        $checkPath = is_dir($path) ? $path : dirname($path);

        if (!file_exists($checkPath)) {
            return [
                "status" => "fail",
                "error" => "Path does not exist: {$checkPath}",
            ];
        }

        $freeSpace = @disk_free_space($checkPath);

        if ($freeSpace === false) {
            return [
                "status" => "fail",
                "error" => "Unable to determine disk space",
            ];
        }

        // Cast to int for consistent return type (disk_free_space can return float)
        $freeSpace = (int) $freeSpace;
        $minSpace = $this->config["min_disk_space"];
        $isHealthy = $freeSpace >= $minSpace;

        return [
            "status" => $isHealthy ? "ok" : "fail",
            "available" => $this->formatBytes($freeSpace),
            "available_bytes" => $freeSpace,
            "minimum_required" => $this->formatBytes($minSpace),
            "minimum_required_bytes" => $minSpace,
        ];
    }

    /**
     * Check if cache directory is writable
     *
     * @param string $path Directory path to check
     * @return array Check result with status and permission information
     */
    private function checkPermissions(string $path): array
    {
        if (empty($path)) {
            return [
                "status" => "fail",
                "error" => "No path provided for permissions check",
            ];
        }

        // If directory doesn't exist, check if we can create it
        if (!file_exists($path)) {
            // Find the first existing parent directory
            $parentDir = dirname($path);
            while (
                !file_exists($parentDir) &&
                $parentDir !== "/" &&
                $parentDir !== "."
            ) {
                $parentDir = dirname($parentDir);
            }

            if (!is_dir($parentDir)) {
                return [
                    "status" => "fail",
                    "error" => "No writable parent directory found",
                    "writable" => false,
                ];
            }

            $canCreate = is_writable($parentDir);

            return [
                "status" => $canCreate ? "ok" : "fail",
                "writable" => $canCreate,
                "exists" => false,
                "message" => $canCreate
                    ? "Directory can be created"
                    : "Cannot create directory - parent not writable",
            ];
        }

        // Check if existing path is writable
        $isWritable = is_writable($path);

        if (!$isWritable) {
            return [
                "status" => "fail",
                "error" => "Directory is not writable",
                "writable" => false,
                "exists" => true,
            ];
        }

        // Verify by attempting to write a test file
        $testFile = rtrim($path, "/") . "/.health_check_" . uniqid();
        $canWrite = @file_put_contents($testFile, "test") !== false;

        if ($canWrite) {
            @unlink($testFile);
        }

        return [
            "status" => $canWrite ? "ok" : "fail",
            "writable" => $canWrite,
            "exists" => true,
        ];
    }

    /**
     * Check PHP version meets minimum requirement
     *
     * @return array Check result with status and version information
     */
    private function checkPhpVersion(): array
    {
        $currentVersion = PHP_VERSION;
        $minVersion = $this->config["min_php_version"];

        $meetsRequirement = version_compare($currentVersion, $minVersion, ">=");

        $result = [
            "status" => $meetsRequirement ? "ok" : "fail",
            "version" => $currentVersion,
            "minimum_required" => $minVersion,
        ];

        if (!$meetsRequirement) {
            $result[
                "error"
            ] = "PHP version {$currentVersion} is below minimum {$minVersion}";
        }

        return $result;
    }

    /**
     * Check available memory
     *
     * @return array Check result with status and memory information
     */
    private function checkMemory(): array
    {
        $memoryLimit = $this->parseMemoryLimit(ini_get("memory_limit"));

        if ($memoryLimit === -1) {
            // No memory limit
            return [
                "status" => "ok",
                "available" => "unlimited",
                "available_bytes" => -1,
                "usage" => $this->formatBytes(memory_get_usage(true)),
                "usage_bytes" => memory_get_usage(true),
            ];
        }

        $currentUsage = memory_get_usage(true);
        $available = $memoryLimit - $currentUsage;
        $minRequired = $this->config["min_memory"];

        $isHealthy = $available >= $minRequired;

        $result = [
            "status" => $isHealthy ? "ok" : "fail",
            "available" => $this->formatBytes($available),
            "available_bytes" => $available,
            "limit" => $this->formatBytes($memoryLimit),
            "limit_bytes" => $memoryLimit,
            "usage" => $this->formatBytes($currentUsage),
            "usage_bytes" => $currentUsage,
            "minimum_required" => $this->formatBytes($minRequired),
            "minimum_required_bytes" => $minRequired,
        ];

        if (!$isHealthy) {
            $result[
                "error"
            ] = "Insufficient memory available ({$result["available"]} < {$result["minimum_required"]})";
        }

        return $result;
    }

    /**
     * Parse memory limit string to bytes
     *
     * @param string $value Memory limit value (e.g., "128M", "1G")
     * @return int Memory limit in bytes, or -1 for unlimited
     */
    private function parseMemoryLimit(string $value): int
    {
        $value = trim($value);

        if ($value === "-1") {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        switch ($unit) {
            case "g":
                $number *= 1024;
            // fall through
            case "m":
                $number *= 1024;
            // fall through
            case "k":
                $number *= 1024;
        }

        return $number;
    }

    /**
     * Format bytes to human-readable string
     *
     * @param int|float $bytes Number of bytes
     * @return string Formatted string (e.g., "1.5GB", "512MB")
     */
    private function formatBytes(int|float $bytes): string
    {
        if ($bytes < 0) {
            return "unlimited";
        }

        $units = ["B", "KB", "MB", "GB", "TB"];
        $i = 0;
        $value = $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        // Format with appropriate precision
        if ($value >= 100) {
            return round($value) . $units[$i];
        } elseif ($value >= 10) {
            return round($value, 1) . $units[$i];
        } else {
            return round($value, 2) . $units[$i];
        }
    }

    /**
     * Check multiple systems in batch
     *
     * @param array $inputs Array of input arrays
     * @return array Array of health check results
     */
    public function checkBatch(array $inputs): array
    {
        $results = [];
        foreach ($inputs as $index => $input) {
            $results[$index] = $this->check($input);
        }
        return $results;
    }

    /**
     * Quick health check - runs all checks with default settings
     *
     * @param string $cacheDir Cache directory to check
     * @return bool True if all checks pass
     */
    public function isHealthy(string $cacheDir): bool
    {
        $result = $this->check(["cache_dir" => $cacheDir]);
        return $result["healthy"];
    }
}
