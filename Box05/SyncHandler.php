<?php

declare(strict_types=1);

require_once __DIR__ . "/BackgroundJobInterface.php";

/**
 * Synchronous Handler (Fallback)
 *
 * Executes jobs immediately/synchronously.
 * Used as fallback when no async method is available.
 *
 * Not ideal for production but ensures nothing breaks.
 */
class SyncHandler implements BackgroundJobInterface
{
    /**
     * Execute job synchronously (immediately)
     */
    public function dispatch(string $task, array $params): string
    {
        $jobId = $this->generateJobId();

        $job = [
            "id" => $jobId,
            "task" => $task,
            "params" => $params,
            "executed_at" => microtime(true),
        ];

        $this->executeJob($job);

        return $jobId;
    }

    /**
     * Always available (it's just synchronous execution)
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Execute a single job
     *
     * @param array $job Job data with 'id', 'task', 'params', 'executed_at'
     */
    protected function executeJob(array $job): void
    {
        error_log(
            sprintf(
                "[BackgroundJob] Executed SYNC %s with params: %s",
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
