<?php

declare(strict_types=1);

require_once __DIR__ . "/BackgroundJobInterface.php";
require_once __DIR__ . "/FastCGIHandler.php";
require_once __DIR__ . "/SyncHandler.php";

/**
 * Background Job Dispatcher
 *
 * Simple, extensible background job dispatcher for ISR (Incremental Static Regeneration).
 * Queues jobs to execute after HTTP response is sent.
 *
 * Features:
 * - Interface-based design (easy to extend)
 * - Default FastCGI handler (works with PHP-FPM)
 * - Automatic fallback to sync execution
 * - Plug in your own queue system (Laravel, WordPress, Redis, etc.)
 *
 * @example
 * // Use default handler
 * $dispatcher = new BackgroundDispatcher();
 * $jobId = $dispatcher->dispatch([
 *     'task' => 'regenerate',
 *     'params' => ['url' => '/blog/post-123']
 * ]);
 *
 * @example
 * // Use custom handler
 * $dispatcher = new BackgroundDispatcher(new LaravelQueueAdapter());
 * $jobId = $dispatcher->dispatch([
 *     'task' => 'send_email',
 *     'params' => ['to' => 'user@example.com']
 * ]);
 */
class BackgroundDispatcher
{
    private BackgroundJobInterface $handler;

    /**
     * Create dispatcher with optional custom handler
     *
     * @param BackgroundJobInterface|null $handler Custom handler or null for auto-detect
     */
    public function __construct(?BackgroundJobInterface $handler = null)
    {
        $this->handler = $handler ?? $this->detectHandler();
    }

    /**
     * Dispatch a background job
     *
     * @param array $config Job configuration with 'task' and optional 'params'
     * @return array Result with job_id and handler type
     */
    public function dispatch(array $config): array
    {
        // Validate input
        if (
            !isset($config["task"]) ||
            !is_string($config["task"]) ||
            trim($config["task"]) === ""
        ) {
            throw new InvalidArgumentException(
                "Task must be a non-empty string",
            );
        }

        $task = $config["task"];
        $params = $config["params"] ?? [];

        // Dispatch via handler
        $jobId = $this->handler->dispatch($task, $params);

        return [
            "queued" => true,
            "job_id" => $jobId,
            "method_used" => $this->getHandlerName(),
        ];
    }

    /**
     * Batch dispatch multiple jobs
     *
     * @param array $jobs Array of job configs
     * @return array Results for all jobs
     */
    public function dispatchBatch(array $jobs): array
    {
        $results = [];
        foreach ($jobs as $job) {
            $results[] = $this->dispatch($job);
        }
        return $results;
    }

    /**
     * Get the current handler
     */
    public function getHandler(): BackgroundJobInterface
    {
        return $this->handler;
    }

    /**
     * Get handler name for debugging
     */
    private function getHandlerName(): string
    {
        return match (get_class($this->handler)) {
            "FastCGIHandler" => "fastcgi",
            "SyncHandler" => "sync",
            default => strtolower(
                str_replace("Handler", "", get_class($this->handler)),
            ),
        };
    }

    /**
     * Auto-detect best available handler
     */
    private function detectHandler(): BackgroundJobInterface
    {
        // Try FastCGI first (best for PHP-FPM)
        $fastcgi = new FastCGIHandler();
        if ($fastcgi->isAvailable()) {
            return $fastcgi;
        }

        // Fallback to sync
        return new SyncHandler();
    }
}
