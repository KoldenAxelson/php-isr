<?php

declare(strict_types=1);

/**
 * Background Job Interface
 *
 * Contract for dispatching background jobs after HTTP response.
 * Implement this interface to integrate with any queue system.
 */
interface BackgroundJobInterface
{
    /**
     * Dispatch a background job
     *
     * @param string $task Task identifier
     * @param array $params Task parameters
     * @return string Unique job ID
     */
    public function dispatch(string $task, array $params): string;

    /**
     * Check if this handler can run in current environment
     *
     * @return bool True if handler is available
     */
    public function isAvailable(): bool;
}
