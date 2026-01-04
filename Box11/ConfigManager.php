<?php

declare(strict_types=1);

require_once __DIR__ . "/ConfigException.php";

/**
 * ConfigManager
 *
 * Type-safe configuration manager for ISR (Incremental Static Regeneration).
 * Loads config from PHP arrays, JSON files, or environment variables with
 * validation, defaults, and nested dot notation support.
 *
 * Features:
 * - Multiple input sources (array, file, env)
 * - Type-safe getters with validation
 * - Nested config with dot notation (e.g., 'cache.dir')
 * - Immutable by design (no setters)
 * - ISR-specific defaults and validation
 * - Path traversal protection
 * - Security validation for sensitive values
 *
 * Security:
 * - Path traversal protection for file loading
 * - Validation prevents empty critical paths
 * - Warning detection for hardcoded secrets
 * - File permission checks
 */
class ConfigManager
{
    /** @var array<string, mixed> Configuration data */
    private array $config = [];

    /** @var bool Whether config is locked (finalized) */
    private bool $locked = false;

    /** @var string|null Base directory for allowed config files */
    private ?string $allowedBasePath = null;

    /** @var array<string, mixed> Default ISR configuration */
    private const DEFAULTS = [
        "cache" => [
            "dir" => "./cache",
            "default_ttl" => 3600,
            "use_sharding" => false,
        ],
        "freshness" => [
            "stale_window_seconds" => null, // null = use TTL
        ],
        "background" => [
            "timeout" => 30,
            "max_retries" => 3,
        ],
        "stats" => [
            "enabled" => true,
            "file" => null, // null = memory only
        ],
        "compression" => [
            "enabled" => false,
            "level" => 6, // gzip level 1-9
        ],
    ];

    /**
     * Known top-level configuration sections
     *
     * Used to properly parse environment variables with underscores in key names.
     * For example: ISR_CACHE_DEFAULT_TTL should become cache.default_ttl,
     * not cache.default.ttl
     *
     * For known sections, underscores in the key portion are preserved.
     * For custom sections, underscores create nested structure.
     */
    private const KNOWN_SECTIONS = [
        "cache",
        "freshness",
        "background",
        "stats",
        "compression",
    ];

    /**
     * Sensitive configuration keys that should come from environment
     *
     * Used to warn if secrets are hardcoded in config files
     */
    private const SENSITIVE_KEYS = [
        "password",
        "secret",
        "api_key",
        "apikey",
        "token",
        "private_key",
        "privatekey",
        "credentials",
    ];

    /**
     * Create from array
     *
     * @param array<string, mixed> $config Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->mergeWithDefaults($config);
    }

    /**
     * Set allowed base path for config files (security)
     *
     * When set, only files within this directory can be loaded.
     * Prevents path traversal attacks.
     *
     * @param string $basePath Allowed base directory
     * @return self
     */
    public function setAllowedBasePath(string $basePath): self
    {
        $realPath = realpath($basePath);
        if ($realPath === false || !is_dir($realPath)) {
            throw new ConfigSecurityException(
                "Allowed base path does not exist or is not a directory: {$basePath}",
            );
        }

        $this->allowedBasePath = $realPath;
        return $this;
    }

    /**
     * Load from PHP file
     *
     * File should return an array
     *
     * @param string $filepath Path to PHP config file
     * @param string|null $allowedBasePath Optional base path restriction
     * @return self
     * @throws ConfigFileException If file doesn't exist or doesn't return array
     * @throws ConfigSecurityException If path traversal detected
     */
    public static function fromFile(
        string $filepath,
        ?string $allowedBasePath = null,
    ): self {
        $instance = new self();

        if ($allowedBasePath !== null) {
            $instance->setAllowedBasePath($allowedBasePath);
        }

        $instance->validateFilePath($filepath);

        if (!file_exists($filepath)) {
            throw new ConfigFileException("Config file not found: {$filepath}");
        }

        if (!is_readable($filepath)) {
            throw new ConfigFileException(
                "Config file is not readable: {$filepath}",
            );
        }

        // Suppress errors during require and catch them
        $config = @require $filepath;

        if ($config === false || $config === 1) {
            // require returns 1 if file has no return statement
            throw new ConfigFileException(
                "Failed to load config file or file has syntax errors: {$filepath}",
            );
        }

        if (!is_array($config)) {
            throw new ConfigFileException(
                "Config file must return an array, got " .
                    gettype($config) .
                    ": {$filepath}",
            );
        }

        $instance->config = $instance->mergeWithDefaults($config);
        return $instance;
    }

    /**
     * Load from JSON file
     *
     * @param string $filepath Path to JSON config file
     * @param string|null $allowedBasePath Optional base path restriction
     * @return self
     * @throws ConfigFileException If file doesn't exist or invalid JSON
     * @throws ConfigSecurityException If path traversal detected
     */
    public static function fromJson(
        string $filepath,
        ?string $allowedBasePath = null,
    ): self {
        $instance = new self();

        if ($allowedBasePath !== null) {
            $instance->setAllowedBasePath($allowedBasePath);
        }

        $instance->validateFilePath($filepath);

        if (!file_exists($filepath)) {
            throw new ConfigFileException("Config file not found: {$filepath}");
        }

        if (!is_readable($filepath)) {
            throw new ConfigFileException(
                "Config file is not readable: {$filepath}",
            );
        }

        $json = @file_get_contents($filepath);
        if ($json === false) {
            throw new ConfigFileException(
                "Failed to read config file: {$filepath}",
            );
        }

        $config = json_decode($json, true);

        // Check for JSON errors (handles both invalid JSON and valid null)
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigFileException(
                "Invalid JSON in config file: {$filepath} - " .
                    json_last_error_msg(),
            );
        }

        // Handle valid JSON null
        if ($config === null) {
            $config = [];
        }

        if (!is_array($config)) {
            throw new ConfigFileException(
                "Config file must contain a JSON object, got " .
                    gettype($config) .
                    ": {$filepath}",
            );
        }

        $instance->config = $instance->mergeWithDefaults($config);
        return $instance;
    }

    /**
     * Load from environment variables
     *
     * Looks for variables prefixed with the given prefix and converts them:
     * ISR_CACHE_DIR -> cache.dir
     * ISR_CACHE_DEFAULT_TTL -> cache.default_ttl
     * ISR_STATS_ENABLED -> stats.enabled
     *
     * Security: Environment variables are the recommended way to handle
     * sensitive configuration values (passwords, API keys, etc.)
     *
     * @param string $prefix Environment variable prefix (default: 'ISR_')
     * @return self
     */
    public static function fromEnv(string $prefix = "ISR_"): self
    {
        $config = [];
        $prefixLen = strlen($prefix);

        // Collect keys from both $_ENV and getenv()
        $envKeys = array_keys($_ENV);
        $getenvKeys = array_keys(getenv() ?: []);
        $keys = array_unique(array_merge($envKeys, $getenvKeys));

        foreach ($keys as $key) {
            if (!is_string($key) || strpos($key, $prefix) !== 0) {
                continue;
            }

            // Get value - prefer $_ENV over getenv()
            $value = $_ENV[$key] ?? getenv($key);

            if ($value === false || $value === "") {
                continue;
            }

            // Remove prefix and convert to nested array
            $configKey = substr($key, $prefixLen);
            $configKey = strtolower($configKey);
            $parts = explode("_", $configKey);

            // Check if first part is a known section
            if (
                count($parts) > 1 &&
                in_array($parts[0], self::KNOWN_SECTIONS, true)
            ) {
                // First element is a known section, join the rest with underscores
                $section = array_shift($parts);
                $subkey = implode("_", $parts);
                $parts = [$section, $subkey];
            }
            // Otherwise, keep the normal splitting behavior for custom sections

            // Type cast the value
            $value = self::castEnvValue($value);

            // Build nested array
            self::setNestedValue($config, $parts, $value);
        }

        return new self($config);
    }

    /**
     * Get config value with dot notation support
     *
     * @param string $key Config key (supports dot notation: 'cache.dir')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->getNestedValue($this->config, $key);
        return $value !== null ? $value : $default;
    }

    /**
     * Get string value
     *
     * @param string $key Config key
     * @param string $default Default value
     * @return string
     */
    public function getString(string $key, string $default = ""): string
    {
        $value = $this->get($key, $default);
        if (!is_string($value)) {
            return $default;
        }
        return $value;
    }

    /**
     * Get integer value
     *
     * @param string $key Config key
     * @param int $default Default value
     * @return int
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Get boolean value
     *
     * @param string $key Config key
     * @param bool $default Default value
     * @return bool
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === "true" || $lower === "1") {
                return true;
            }
            if ($lower === "false" || $lower === "0" || $lower === "") {
                return false;
            }
        }
        return $default;
    }

    /**
     * Get float value
     *
     * @param string $key Config key
     * @param float $default Default value
     * @return float
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);
        if (is_float($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        return $default;
    }

    /**
     * Get array value
     *
     * @param string $key Config key
     * @param array<mixed> $default Default value
     * @return array<mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * Check if config key exists
     *
     * @param string $key Config key (supports dot notation)
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->getNestedValue($this->config, $key) !== null;
    }

    /**
     * Get cache directory
     *
     * @return string
     */
    public function getCacheDir(): string
    {
        return $this->getString("cache.dir", self::DEFAULTS["cache"]["dir"]);
    }

    /**
     * Get default TTL
     *
     * @return int
     */
    public function getDefaultTTL(): int
    {
        return $this->getInt(
            "cache.default_ttl",
            self::DEFAULTS["cache"]["default_ttl"],
        );
    }

    /**
     * Get whether sharding is enabled
     *
     * @return bool
     */
    public function useSharding(): bool
    {
        return $this->getBool(
            "cache.use_sharding",
            self::DEFAULTS["cache"]["use_sharding"],
        );
    }

    /**
     * Get stale window seconds
     *
     * @return int|null
     */
    public function getStaleWindowSeconds(): ?int
    {
        $value = $this->get(
            "freshness.stale_window_seconds",
            self::DEFAULTS["freshness"]["stale_window_seconds"],
        );

        if ($value === null) {
            return null;
        }

        // Ensure it's a valid integer
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        // If it's a string "null", return null
        if (is_string($value) && strtolower($value) === "null") {
            return null;
        }

        return null;
    }

    /**
     * Get background job timeout
     *
     * @return int
     */
    public function getBackgroundTimeout(): int
    {
        return $this->getInt(
            "background.timeout",
            self::DEFAULTS["background"]["timeout"],
        );
    }

    /**
     * Get whether stats collection is enabled
     *
     * @return bool
     */
    public function isStatsEnabled(): bool
    {
        return $this->getBool(
            "stats.enabled",
            self::DEFAULTS["stats"]["enabled"],
        );
    }

    /**
     * Get whether compression is enabled
     *
     * @return bool
     */
    public function isCompressionEnabled(): bool
    {
        return $this->getBool(
            "compression.enabled",
            self::DEFAULTS["compression"]["enabled"],
        );
    }

    /**
     * Get all configuration
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Mark configuration as finalized (semantic marker)
     *
     * Note: Configuration is already immutable by design as no setter methods exist.
     * This method serves as a semantic marker to indicate the configuration is
     * finalized and ready for production use. The flag can be checked by application
     * code to ensure config is validated before use.
     *
     * Recommended pattern:
     * ```php
     * $config = ConfigManager::fromFile('config.php');
     * $validation = $config->validate();
     * if (!$validation['valid']) {
     *     throw new Exception('Invalid config');
     * }
     * $config->lock(); // Mark as ready for production
     * ```
     *
     * @return self Chainable instance
     */
    public function lock(): self
    {
        $this->locked = true;
        return $this;
    }

    /**
     * Check if configuration is locked (finalized)
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Validate configuration
     *
     * Checks for required values, valid types/ranges, and security concerns.
     *
     * @return array{valid: bool, errors: string[], warnings: string[]} Validation result
     */
    public function validate(): array
    {
        $errors = [];
        $warnings = [];

        // Validate cache directory
        $cacheDir = $this->getCacheDir();
        if (empty($cacheDir)) {
            $errors[] = "cache.dir cannot be empty";
        } elseif ($cacheDir === ".") {
            $warnings[] =
                "cache.dir is '.', consider using an absolute path for production";
        }

        // Validate TTL
        $ttl = $this->getDefaultTTL();
        if ($ttl < 0) {
            $errors[] = "cache.default_ttl must be >= 0";
        }

        // Validate stale window
        $staleWindow = $this->getStaleWindowSeconds();
        if ($staleWindow !== null && $staleWindow < 0) {
            $errors[] = "freshness.stale_window_seconds must be >= 0 or null";
        }

        // Validate background timeout
        $timeout = $this->getBackgroundTimeout();
        if ($timeout <= 0) {
            $errors[] = "background.timeout must be > 0";
        }

        // Validate max retries
        $maxRetries = $this->getInt("background.max_retries", 3);
        if ($maxRetries < 0) {
            $errors[] = "background.max_retries must be >= 0";
        }

        // Validate compression level
        if ($this->isCompressionEnabled()) {
            $level = $this->getInt("compression.level", 6);
            if ($level < 1 || $level > 9) {
                $errors[] = "compression.level must be between 1-9";
            }
        }

        // Security: Check for hardcoded sensitive values
        $this->checkForSensitiveValues($this->config, [], $warnings);

        return [
            "valid" => empty($errors),
            "errors" => $errors,
            "warnings" => $warnings,
        ];
    }

    /**
     * Export configuration to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Export configuration to JSON
     *
     * @param bool $pretty Pretty print JSON
     * @return string
     */
    public function toJson(bool $pretty = false): string
    {
        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;
        $json = json_encode($this->config, $flags);

        if ($json === false) {
            throw new ConfigException(
                "Failed to encode config to JSON: " . json_last_error_msg(),
            );
        }

        return $json;
    }

    /**
     * Validate file path for security
     *
     * Prevents path traversal attacks by ensuring the resolved path
     * is within the allowed base path (if set).
     *
     * @param string $filepath File path to validate
     * @throws ConfigSecurityException If path traversal detected
     */
    private function validateFilePath(string $filepath): void
    {
        // Check for null bytes (security)
        if (strpos($filepath, "\0") !== false) {
            throw new ConfigSecurityException(
                "Invalid file path: contains null byte",
            );
        }

        // If no base path restriction, allow any path
        if ($this->allowedBasePath === null) {
            return;
        }

        // Resolve the real path
        $realPath = realpath($filepath);

        // If file doesn't exist yet, try to resolve the directory
        if ($realPath === false) {
            $dir = dirname($filepath);
            $realDir = realpath($dir);
            if ($realDir === false) {
                throw new ConfigSecurityException(
                    "Cannot validate path, directory does not exist: {$dir}",
                );
            }
            $realPath = $realDir . DIRECTORY_SEPARATOR . basename($filepath);
        }

        // Check if resolved path is within allowed base path
        if (strpos($realPath, $this->allowedBasePath) !== 0) {
            throw new ConfigSecurityException(
                "Path traversal detected: {$filepath} is outside allowed base path",
            );
        }
    }

    /**
     * Check for hardcoded sensitive values (security)
     *
     * Warns if configuration contains keys that look like they might
     * contain secrets (password, api_key, etc.)
     *
     * @param array<string, mixed> $config Config array to check
     * @param array<string> $path Current path (for recursion)
     * @param array<string> $warnings Warnings array (passed by reference)
     */
    private function checkForSensitiveValues(
        array $config,
        array $path,
        array &$warnings,
    ): void {
        foreach ($config as $key => $value) {
            $currentPath = array_merge($path, [$key]);
            $pathString = implode(".", $currentPath);

            // Check if key name looks sensitive
            $keyLower = strtolower((string) $key);
            foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
                if (strpos($keyLower, $sensitiveKey) !== false) {
                    // Found a sensitive-looking key
                    if (is_string($value) && $value !== "" && $value !== null) {
                        $warnings[] =
                            "Potentially sensitive value in '{$pathString}' - " .
                            "consider using environment variables for secrets";
                    }
                    break;
                }
            }

            // Recurse into nested arrays
            if (is_array($value)) {
                $this->checkForSensitiveValues($value, $currentPath, $warnings);
            }
        }
    }

    /**
     * Merge user config with defaults (deep merge)
     *
     * @param array<string, mixed> $config User configuration
     * @return array<string, mixed> Merged configuration
     */
    private function mergeWithDefaults(array $config): array
    {
        return $this->arrayMergeRecursive(self::DEFAULTS, $config);
    }

    /**
     * Recursively merge arrays (user values override defaults)
     *
     * @param array<string, mixed> $default Default values
     * @param array<string, mixed> $user User values
     * @return array<string, mixed> Merged array
     */
    private function arrayMergeRecursive(array $default, array $user): array
    {
        $result = $default;

        foreach ($user as $key => $value) {
            if (
                is_array($value) &&
                isset($result[$key]) &&
                is_array($result[$key])
            ) {
                $result[$key] = $this->arrayMergeRecursive(
                    $result[$key],
                    $value,
                );
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get nested value using dot notation
     *
     * @param array<string, mixed> $array Array to search
     * @param string $key Dot-notated key
     * @return mixed|null
     */
    private function getNestedValue(array $array, string $key)
    {
        // Check for direct key first (optimization)
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $parts = explode(".", $key);
        $current = $array;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * Set nested value in array using parts
     *
     * @param array<string, mixed> $array Array to modify (passed by reference)
     * @param array<string> $parts Key parts
     * @param mixed $value Value to set
     */
    private static function setNestedValue(
        array &$array,
        array $parts,
        $value,
    ): void {
        $current = &$array;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }
    }

    /**
     * Cast environment variable value to appropriate type
     *
     * @param string $value Environment variable value
     * @return mixed
     */
    private static function castEnvValue(string $value)
    {
        // Boolean
        $lower = strtolower($value);
        if ($lower === "true") {
            return true;
        }
        if ($lower === "false") {
            return false;
        }

        // Null
        if ($lower === "null") {
            return null;
        }

        // Integer (check for negative numbers too)
        if (ctype_digit($value) || preg_match('/^-\d+$/', $value) === 1) {
            return (int) $value;
        }

        // Float (handles negative floats too)
        if (is_numeric($value)) {
            // Check if it's actually a float (has decimal point)
            if (strpos($value, ".") !== false) {
                return (float) $value;
            }
        }

        // String (default)
        return $value;
    }
}
