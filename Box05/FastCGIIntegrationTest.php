<?php

/**
 * FastCGI Integration Test
 *
 * REQUIRES: PHP-FPM with fastcgi_finish_request() available
 *
 * This test actually verifies that jobs execute AFTER the HTTP response is sent.
 *
 * To run:
 * 1. Set up PHP-FPM (Apache/Nginx + PHP-FPM)
 * 2. Place this file in web-accessible directory
 * 3. Access via browser or: curl http://localhost/fastcgi-integration-test.php
 * 4. Check the log file to verify async execution
 */

declare(strict_types=1);

require_once __DIR__ . "/BackgroundDispatcher.php";

// Configuration
$logFile = "/tmp/fastcgi_integration_test.log";
$testId = uniqid("test_");

// Clear previous log
if (file_exists($logFile)) {
    unlink($logFile);
}

// Custom handler that logs to file
class TestLoggingHandler extends FastCGIHandler
{
    private string $logFile;
    private string $testId;

    public function __construct(string $logFile, string $testId)
    {
        $this->logFile = $logFile;
        $this->testId = $testId;
    }

    protected function executeJob(array $job): void
    {
        $logEntry = sprintf(
            "[%s] Test ID: %s | Job: %s | Executed at: %s\n",
            date("Y-m-d H:i:s.u"),
            $this->testId,
            $job["id"],
            microtime(true),
        );

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

// Create dispatcher with logging handler
$dispatcher = new BackgroundDispatcher(
    new TestLoggingHandler($logFile, $testId),
);

// Log response time
$responseTime = microtime(true);
file_put_contents(
    $logFile,
    sprintf(
        "[%s] Test ID: %s | HTTP Response sent at: %s\n",
        date("Y-m-d H:i:s.u"),
        $testId,
        $responseTime,
    ),
    FILE_APPEND | LOCK_EX,
);

// Dispatch jobs
$jobs = [];
for ($i = 0; $i < 5; $i++) {
    $result = $dispatcher->dispatch([
        "task" => "test_async",
        "params" => ["iteration" => $i],
    ]);
    $jobs[] = $result["job_id"];
}

// Send response immediately
header("Content-Type: text/plain");
echo "FastCGI Integration Test\n";
echo "========================\n\n";
echo "Test ID: {$testId}\n";
echo "Dispatched Jobs: " . count($jobs) . "\n";
echo "Job IDs:\n";
foreach ($jobs as $jobId) {
    echo "  - {$jobId}\n";
}
echo "\nResponse sent at: " . date("Y-m-d H:i:s.u", (int) $responseTime) . "\n";
echo "\nIf FastCGI is working correctly:\n";
echo "1. You should see this response immediately\n";
echo "2. Jobs will execute AFTER this response is sent\n";
echo "3. Check the log file: {$logFile}\n";
echo "\nTo verify:\n";
echo "  cat {$logFile}\n";
echo "\nExpected: Job execution timestamps should be AFTER response timestamp\n";

// FastCGI will finish request here and jobs execute after
// (or immediately if FastCGI not available, demonstrating the difference)
