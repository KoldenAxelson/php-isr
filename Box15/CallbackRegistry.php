<?php

/**
 * CallbackRegistry
 *
 * Registry for content generation callbacks. Maps string names to callable
 * functions, allowing background jobs to reference callbacks without
 * serializing closures.
 *
 * Solves the PHP background job serialization problem where closures cannot
 * be serialized and passed to child processes.
 */
class CallbackRegistry
{
    /**
     * Internal storage for callbacks and metadata
     *
     * @var array<string, array{callback: callable, metadata: array, registered_at: int}>
     */
    private array $callbacks = [];

    /**
     * Register a content generation callback
     *
     * @param string $name Unique name for the callback
     * @param callable $callback Content generation function
     * @param array $metadata Optional metadata (description, TTL hint, etc.)
     * @return void
     * @throws InvalidArgumentException If name already registered or invalid
     */
    public function register(
        string $name,
        callable $callback,
        array $metadata = [],
    ): void {
        // Validate callback name
        if (empty($name)) {
            throw new InvalidArgumentException("Callback name cannot be empty");
        }

        // Validate name format (prevent special characters that could cause issues)
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $name)) {
            throw new InvalidArgumentException(
                "Callback name must contain only alphanumeric characters, underscores, hyphens, and dots",
            );
        }

        // Check for duplicate registration
        if (isset($this->callbacks[$name])) {
            throw new InvalidArgumentException(
                "Callback '{$name}' is already registered",
            );
        }

        // Store callback with metadata and timestamp
        $this->callbacks[$name] = [
            "callback" => $callback,
            "metadata" => $metadata,
            "registered_at" => time(),
        ];
    }

    /**
     * Get a registered callback
     *
     * @param string $name Callback name
     * @return callable|null Callback or null if not found
     */
    public function get(string $name): ?callable
    {
        if (!isset($this->callbacks[$name])) {
            return null;
        }

        return $this->callbacks[$name]["callback"];
    }

    /**
     * Check if callback is registered
     *
     * @param string $name Callback name
     * @return bool True if registered
     */
    public function has(string $name): bool
    {
        return isset($this->callbacks[$name]);
    }

    /**
     * List all registered callback names
     *
     * @return array Array of callback names
     */
    public function list(): array
    {
        return array_keys($this->callbacks);
    }

    /**
     * Get metadata for a callback
     *
     * @param string $name Callback name
     * @return array|null Metadata or null if not found
     */
    public function getMetadata(string $name): ?array
    {
        if (!isset($this->callbacks[$name])) {
            return null;
        }

        return $this->callbacks[$name]["metadata"];
    }

    /**
     * Get registration timestamp for a callback
     *
     * @param string $name Callback name
     * @return int|null Unix timestamp or null if not found
     */
    public function getRegisteredAt(string $name): ?int
    {
        if (!isset($this->callbacks[$name])) {
            return null;
        }

        return $this->callbacks[$name]["registered_at"];
    }

    /**
     * Remove a registered callback
     *
     * @param string $name Callback name
     * @return bool True if removed, false if not found
     */
    public function unregister(string $name): bool
    {
        if (!isset($this->callbacks[$name])) {
            return false;
        }

        unset($this->callbacks[$name]);
        return true;
    }

    /**
     * Clear all registered callbacks
     * Useful for testing or reset scenarios
     *
     * @return void
     */
    public function clear(): void
    {
        $this->callbacks = [];
    }

    /**
     * Get count of registered callbacks
     *
     * @return int Number of registered callbacks
     */
    public function count(): int
    {
        return count($this->callbacks);
    }

    /**
     * Get all callbacks with their metadata
     * Useful for debugging and inspection
     *
     * @return array Array of callback information (without actual callables)
     */
    public function getAllInfo(): array
    {
        $info = [];
        foreach ($this->callbacks as $name => $data) {
            $info[$name] = [
                "metadata" => $data["metadata"],
                "registered_at" => $data["registered_at"],
                "is_callable" => is_callable($data["callback"]),
            ];
        }
        return $info;
    }
}
