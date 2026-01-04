<?php

declare(strict_types=1);

require_once __DIR__ . "/BackgroundJobInterface.php";

/**
 * FastCGI Background Job Handler (REFERENCE IMPLEMENTATION)
 *
 * This is a base class example showing how to use fastcgi_finish_request()
 * for background job execution. Jobs execute after HTTP response is sent to client.
 *
 * For production ISR use:
 * - Option 1: Extend this class and override executeJob() method
 * - Option 2: Implement BackgroundJobInterface from scratch in your orchestrator
 * - Option 3: Use a framework adapter (Laravel Queue, WordPress Action Scheduler, Redis, etc.)
 *
 * The default executeJob() implementation only logs jobs. You must provide
 * actual task execution logic by extending this class or implementing your own handler.
 *
 * See README.md for orchestrator integration examples.
 *
 * Requirements: PHP-FPM with fastcgi_finish_request() function
 */
class FastCGIHandler implements BackgroundJobInterface
{
    private array $jobs = [];
    private bool $shutdownRegistered = false;

    /**
     * Dispatch a job to run after response
     */
    public function dispatch(string $task, array $params): string
    {
        $jobId = $this->generateJobId();

        $this->jobs[] = [
            "id" => $jobId,
            "task" => $task,
            "params" => $params,
            "queued_at" => microtime(true),
        ];

        // Register shutdown function once
        if (!$this->shutdownRegistered) {
            register_shutdown_function(function () {
                // Send response to client
                if (function_exists("fastcgi_finish_request")) {
                    fastcgi_finish_request();
                }

                // Now execute queued jobs
                $this->executeJobs();
            });
            $this->shutdownRegistered = true;
        }

        return $jobId;
    }

    /**
     * Check if FastCGI is available
     */
    public function isAvailable(): bool
    {
        return function_exists("fastcgi_finish_request");
    }

    /**
     * Execute all queued jobs
     */
    private function executeJobs(): void
    {
        foreach ($this->jobs as $job) {
            $this->executeJob($job);
        }
        $this->jobs = [];
    }

    /**
     * Execute a single job
     *
     * OVERRIDE THIS METHOD in your production handler to provide actual task execution.
     * Default implementation only logs jobs for demonstration purposes.
     *
     * @param array $job Job data with 'id', 'task', 'params', 'queued_at'
     */
    protected function executeJob(array $job): void
    {
        // Default: just log the execution
        // Override this method to route tasks to actual handlers
        error_log(
            sprintf(
                "[BackgroundJob] Executed %s with params: %s",
                $job["task"],
                json_encode($job["params"]),
            ),
        );
    }

    /**
     * Generate unique job ID
     */
    private function generateJobId(): string
    {
        return "job_" . bin2hex(random_bytes(8));
    }
}
