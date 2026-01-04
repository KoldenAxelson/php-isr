<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/BackgroundDispatcher.php";
require_once __DIR__ . "/BackgroundJobInterface.php";
require_once __DIR__ . "/FastCGIHandler.php";
require_once __DIR__ . "/SyncHandler.php";

/**
 * Background Dispatcher Test Suite (PHPUnit)
 */
class BackgroundDispatcherTest extends TestCase
{
    private BackgroundDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new BackgroundDispatcher();
    }

    /**
     * Test basic dispatch functionality
     */
    public function testBasicDispatch(): void
    {
        $result = $this->dispatcher->dispatch([
            "task" => "regenerate",
            "params" => ["url" => "/blog/post-123"],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey("queued", $result);
        $this->assertArrayHasKey("job_id", $result);
        $this->assertArrayHasKey("method_used", $result);
        $this->assertTrue($result["queued"]);
        $this->assertStringStartsWith("job_", $result["job_id"]);
    }

    /**
     * Test auto-detection of handler
     */
    public function testAutoDetection(): void
    {
        $dispatcher = new BackgroundDispatcher();
        $handler = $dispatcher->getHandler();

        $this->assertInstanceOf(BackgroundJobInterface::class, $handler);
    }

    /**
     * Test custom handler injection
     */
    public function testCustomHandler(): void
    {
        // Create a mock handler
        $mockHandler = new class implements BackgroundJobInterface {
            public array $dispatched = [];

            public function dispatch(string $task, array $params): string
            {
                $jobId = "mock_" . uniqid();
                $this->dispatched[] = [
                    "task" => $task,
                    "params" => $params,
                    "id" => $jobId,
                ];
                return $jobId;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        $dispatcher = new BackgroundDispatcher($mockHandler);
        $result = $dispatcher->dispatch([
            "task" => "test_task",
            "params" => ["foo" => "bar"],
        ]);

        $this->assertCount(1, $mockHandler->dispatched);
        $this->assertEquals("test_task", $mockHandler->dispatched[0]["task"]);
        $this->assertEquals(
            ["foo" => "bar"],
            $mockHandler->dispatched[0]["params"],
        );
        $this->assertStringStartsWith("mock_", $result["job_id"]);
    }

    /**
     * Test batch dispatch
     */
    public function testBatchDispatch(): void
    {
        $jobs = [
            ["task" => "job1", "params" => ["id" => 1]],
            ["task" => "job2", "params" => ["id" => 2]],
            ["task" => "job3", "params" => ["id" => 3]],
        ];

        $results = $this->dispatcher->dispatchBatch($jobs);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertTrue($result["queued"]);
            $this->assertArrayHasKey("job_id", $result);
        }
    }

    /**
     * Test dispatch performance - 100 jobs dispatched in <50ms
     *
     * NOTE: This tests dispatch speed, not async execution.
     * In test environment, FastCGI is unavailable so SyncHandler is used.
     * Real async behavior requires PHP-FPM integration testing.
     */
    public function testDispatchPerformance(): void
    {
        $jobCount = 100;
        $jobs = [];

        for ($i = 0; $i < $jobCount; $i++) {
            $jobs[] = [
                "task" => "benchmark_task",
                "params" => ["index" => $i],
            ];
        }

        $startTime = microtime(true);
        $results = $this->dispatcher->dispatchBatch($jobs);
        $endTime = microtime(true);

        $duration = ($endTime - $startTime) * 1000; // Convert to ms

        $this->assertCount($jobCount, $results);
        $this->assertLessThan(
            50,
            $duration,
            "Dispatch overhead should be <50ms, got {$duration}ms",
        );

        // Verify all jobs were dispatched
        foreach ($results as $result) {
            $this->assertTrue($result["queued"]);
            $this->assertArrayHasKey("job_id", $result);
        }
    }

    /**
     * Test handler interface compliance
     */
    public function testHandlerInterfaceCompliance(): void
    {
        $handlers = [new FastCGIHandler(), new SyncHandler()];

        foreach ($handlers as $handler) {
            $this->assertInstanceOf(BackgroundJobInterface::class, $handler);

            // Test dispatch returns string
            $jobId = $handler->dispatch("test", []);
            $this->assertIsString($jobId);

            // Test isAvailable returns bool
            $available = $handler->isAvailable();
            $this->assertIsBool($available);
        }
    }

    /**
     * Test FastCGI handler specifically
     */
    public function testFastCGIHandler(): void
    {
        $handler = new FastCGIHandler();

        $this->assertInstanceOf(BackgroundJobInterface::class, $handler);
        $this->assertIsBool($handler->isAvailable());

        $jobId = $handler->dispatch("test_task", ["param" => "value"]);
        $this->assertStringStartsWith("job_", $jobId);
    }

    /**
     * Test Sync handler specifically
     */
    public function testSyncHandler(): void
    {
        $handler = new SyncHandler();

        $this->assertInstanceOf(BackgroundJobInterface::class, $handler);
        $this->assertTrue($handler->isAvailable()); // Always available

        $jobId = $handler->dispatch("test_task", ["param" => "value"]);
        $this->assertStringStartsWith("job_", $jobId);
    }

    /**
     * Test invalid input - missing task
     */
    public function testMissingTaskThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dispatcher->dispatch([
            "params" => ["test" => "value"],
            // Missing 'task'
        ]);
    }

    /**
     * Test invalid input - empty task
     */
    public function testEmptyTaskThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dispatcher->dispatch([
            "task" => "",
            "params" => [],
        ]);
    }

    /**
     * Test invalid input - non-string task
     */
    public function testNonStringTaskThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dispatcher->dispatch([
            "task" => 123,
            "params" => [],
        ]);
    }

    /**
     * Test dispatch without params (should default to empty array)
     */
    public function testDispatchWithoutParams(): void
    {
        $result = $this->dispatcher->dispatch([
            "task" => "simple_task",
        ]);

        $this->assertTrue($result["queued"]);
        $this->assertArrayHasKey("job_id", $result);
    }

    /**
     * Test getHandler returns current handler
     */
    public function testGetHandler(): void
    {
        $mockHandler = new class implements BackgroundJobInterface {
            public function dispatch(string $task, array $params): string
            {
                return "mock_id";
            }
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $dispatcher = new BackgroundDispatcher($mockHandler);
        $handler = $dispatcher->getHandler();

        $this->assertSame($mockHandler, $handler);
    }
}
