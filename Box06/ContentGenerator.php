<?php

declare(strict_types=1);

/**
 * ContentGenerator
 *
 * Executes callbacks that generate HTML content, captures output, handles errors,
 * and tracks execution time. Designed for wrapping content generation functions
 * in a safe, predictable way.
 */
class ContentGenerator
{
    /**
     * Execute a content generation callback and capture output
     *
     * @param array $input Input with 'generator' callback, optional 'timeout' and 'url'
     * @return array Result with success, html, generation_time_ms, and error fields
     */
    public function execute(array $input): array
    {
        $generator = $input["generator"] ?? null;
        $timeout = $input["timeout"] ?? null;
        $url = $input["url"] ?? null;

        // Validate generator
        if (!is_callable($generator)) {
            return $this->errorResult(
                "Generator must be a callable function",
                0,
            );
        }

        // Set timeout if specified
        $previousTimeLimit = null;
        if ($timeout !== null && $timeout > 0) {
            $previousTimeLimit = ini_get("max_execution_time");
            set_time_limit((int) $timeout);
        }

        $startTime = microtime(true);
        $html = "";
        $error = null;
        $success = false;

        try {
            // Start output buffering to capture all echo/print statements
            ob_start();

            // Execute the generator callback
            $result = $generator();

            // Capture any output
            $html = ob_get_clean();

            // If generator returned a string instead of echoing, use that
            if (is_string($result) && $result !== "") {
                $html = $result;
            }

            $success = true;
        } catch (Throwable $e) {
            // Clean up output buffer on error
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $error = $e->getMessage();
            $success = false;
        } finally {
            // Restore previous time limit
            if ($previousTimeLimit !== null) {
                set_time_limit((int) $previousTimeLimit);
            }
        }

        $endTime = microtime(true);
        $generationTimeMs = (int) round(($endTime - $startTime) * 1000);

        // Check if timeout was exceeded
        if ($timeout !== null && $generationTimeMs > $timeout * 1000) {
            $error =
                $error ??
                "Timeout exceeded: {$generationTimeMs}ms > {$timeout}s";
            $success = false;
        }

        return [
            "success" => $success,
            "html" => $html,
            "generation_time_ms" => $generationTimeMs,
            "error" => $error,
        ];
    }

    /**
     * Execute multiple content generation callbacks in batch
     *
     * @param array $inputs Array of input arrays
     * @return array Array of results
     */
    public function executeBatch(array $inputs): array
    {
        $results = [];
        foreach ($inputs as $index => $input) {
            $results[$index] = $this->execute($input);
        }
        return $results;
    }

    /**
     * Create an error result
     *
     * @param string $message Error message
     * @param int $generationTimeMs Generation time in milliseconds
     * @return array Error result array
     */
    private function errorResult(string $message, int $generationTimeMs): array
    {
        return [
            "success" => false,
            "html" => "",
            "generation_time_ms" => $generationTimeMs,
            "error" => $message,
        ];
    }

    /**
     * Execute a generator with a fallback if it fails
     *
     * @param array $input Primary generator input
     * @param callable $fallback Fallback generator function
     * @return array Result from primary or fallback generator
     */
    public function executeWithFallback(array $input, callable $fallback): array
    {
        $result = $this->execute($input);

        if (!$result["success"]) {
            // Execute fallback
            $fallbackInput = $input;
            $fallbackInput["generator"] = $fallback;
            return $this->execute($fallbackInput);
        }

        return $result;
    }

    /**
     * Verify a generator produces non-empty output
     *
     * @param callable $generator Generator function
     * @return bool True if generator produces output
     */
    public function verifyOutput(callable $generator): bool
    {
        $result = $this->execute(["generator" => $generator]);
        return $result["success"] && !empty(trim($result["html"]));
    }
}
