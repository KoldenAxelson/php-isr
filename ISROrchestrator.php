<?php

declare(strict_types=1);

require_once __DIR__ . "/RequestClassifier.php";
require_once __DIR__ . "/CacheKeyGenerator.php";
require_once __DIR__ . "/FileCacheStore.php";
require_once __DIR__ . "/FreshnessCalculator.php";
require_once __DIR__ . "/BackgroundDispatcher.php";
require_once __DIR__ . "/BackgroundJobInterface.php";
require_once __DIR__ . "/ContentGenerator.php";
require_once __DIR__ . "/ResponseSender.php";
require_once __DIR__ . "/StatsCollector.php";
require_once __DIR__ . "/LockManager.php";
require_once __DIR__ . "/Logger.php";
require_once __DIR__ . "/ConfigManager.php";

/**
 * ISR Background Handler
 *
 * Custom background job handler for ISR-specific tasks.
 * Implements BackgroundJobInterface to integrate with BackgroundDispatcher (Box 5).
 *
 * This handler knows how to route ISR tasks to the appropriate components:
 * - 'regenerate': Regenerate cached content
 * - 'purge': Purge cache entries (future)
 * - 'warmup': Pre-generate cache (future)
 */
class ISRBackgroundHandler implements BackgroundJobInterface
{
    private ContentGenerator $generator;
    private FileCacheStore $cacheStore;
    private ?object $callbackRegistry;
    private StatsCollector $statsCollector;
    private Logger $logger;
    private LockManager $lockManager;
    private ConfigManager $config;
    private array $jobs = [];
    private bool $shutdownRegistered = false;

    /**
     * Create ISR background handler
     *
     * @param ContentGenerator $generator Content generator
     * @param FileCacheStore $cacheStore Cache store
     * @param object|null $callbackRegistry Callback registry
     * @param StatsCollector $statsCollector Stats collector
     * @param Logger $logger Logger
     * @param LockManager $lockManager Lock manager
     * @param ConfigManager $config Configuration
     */
    public function __construct(
        ContentGenerator $generator,
        FileCacheStore $cacheStore,
        ?object $callbackRegistry,
        StatsCollector $statsCollector,
        Logger $logger,
        LockManager $lockManager,
        ConfigManager $config,
    ) {
        $this->generator = $generator;
        $this->cacheStore = $cacheStore;
        $this->callbackRegistry = $callbackRegistry;
        $this->statsCollector = $statsCollector;
        $this->logger = $logger;
        $this->lockManager = $lockManager;
        $this->config = $config;
    }

    /**
     * Dispatch a background job
     *
     * @param string $task Task identifier
     * @param array $params Task parameters
     * @return string Job ID
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
                // Send response to client first
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
     *
     * @return bool True if available
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
     * Execute a single ISR job
     *
     * Routes tasks to appropriate ISR components.
     *
     * @param array $job Job data
     */
    private function executeJob(array $job): void
    {
        $startTime = microtime(true);
        $task = $job["task"];
        $params = $job["params"];

        $this->logger->debug("Executing background job", [
            "job_id" => $job["id"],
            "task" => $task,
        ]);

        try {
            match ($task) {
                "regenerate" => $this->handleRegenerate($params),
                default => $this->logger->warning("Unknown task type", [
                    "task" => $task,
                ]),
            };

            $duration = microtime(true) - $startTime;
            $this->logger->info("Background job completed", [
                "job_id" => $job["id"],
                "task" => $task,
                "duration" => round($duration, 3) . "s",
            ]);
        } catch (Throwable $e) {
            $this->logger->error("Background job failed", [
                "job_id" => $job["id"],
                "task" => $task,
                "error" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle content regeneration task
     *
     * @param array $params Task parameters
     */
    private function handleRegenerate(array $params): void
    {
        $url = $params["url"] ?? "";
        $cacheKey = $params["cache_key"] ?? "";
        $callbackName = $params["callback_name"] ?? null;
        $callbackParams = $params["callback_params"] ?? [];
        $ttl = $params["ttl"] ?? $this->config->getDefaultTTL();
        $variants = $params["variants"] ?? [];

        if (empty($cacheKey)) {
            $this->logger->error("Regenerate: missing cache_key");
            return;
        }

        // Acquire lock to prevent concurrent regeneration
        $lockResult = $this->lockManager->process([
            "key" => $cacheKey,
            "action" => "acquire",
            "timeout" => $this->config->getBackgroundTimeout(),
        ]);

        if (!$lockResult["locked"]) {
            // Another process is already regenerating
            $this->logger->debug("Regenerate: already locked, skipping", [
                "cache_key" => $cacheKey,
            ]);
            return;
        }

        try {
            // Get callback
            $callback = $this->getCallback($callbackName, $callbackParams);

            if ($callback === null) {
                $this->logger->error("Regenerate: callback not found", [
                    "callback_name" => $callbackName,
                ]);
                return;
            }

            // Generate content
            $result = $this->generator->execute([
                "generator" => $callback,
                "timeout" => $this->config->getBackgroundTimeout(),
                "url" => $url,
            ]);

            if (!$result["success"]) {
                $this->logger->error("Regenerate: generation failed", [
                    "url" => $url,
                    "error" => $result["error"],
                ]);
                return;
            }

            // Store in cache
            $metadata = [
                "url" => $url,
                "variants" => $variants,
                "regenerated_at" => time(),
                "background" => true,
            ];

            $this->cacheStore->write(
                $cacheKey,
                $result["html"],
                $ttl,
                $metadata,
            );

            // Record stats
            $this->statsCollector->record([
                "event" => "generation",
                "metadata" => [
                    "duration" => $result["generation_time_ms"] / 1000,
                ],
            ]);

            $this->logger->info("Regenerate: success", [
                "cache_key" => $cacheKey,
                "url" => $url,
                "generation_time_ms" => $result["generation_time_ms"],
                "size" => strlen($result["html"]),
            ]);
        } finally {
            // Always release lock
            $this->lockManager->process([
                "key" => $cacheKey,
                "action" => "release",
            ]);
        }
    }

    /**
     * Get callback for content generation
     *
     * @param string|null $callbackName Callback name
     * @param array $callbackParams Callback parameters
     * @return callable|null Callback or null if not found
     */
    private function getCallback(
        ?string $callbackName,
        array $callbackParams,
    ): ?callable {
        if ($callbackName === null) {
            return null;
        }

        // Try to get from registry
        if (
            $this->callbackRegistry &&
            method_exists($this->callbackRegistry, "get")
        ) {
            $callback = $this->callbackRegistry->get($callbackName);

            if ($callback !== null) {
                // Wrap callback to pass parameters
                return function () use ($callback, $callbackParams) {
                    return $callback($callbackParams);
                };
            }
        }

        return null;
    }

    /**
     * Generate unique job ID
     *
     * @return string Job ID
     */
    private function generateJobId(): string
    {
        return "job_" . bin2hex(random_bytes(8));
    }
}

/**
 * ISR Orchestrator
 *
 * Main coordinator for Incremental Static Regeneration (ISR) in PHP.
 * Implements the ISR pattern: serve stale content while regenerating in background.
 *
 * Flow:
 * 1. Classify request (cacheable?)
 * 2. Generate cache key
 * 3. Check cache freshness
 * 4. FRESH: Serve immediately
 * 5. STALE: Serve + queue background regeneration
 * 6. EXPIRED/MISSING: Generate synchronously
 * 7. Update cache and send response
 *
 * @example
 * $orchestrator = new ISROrchestrator();
 * $orchestrator->handleRequest('/blog/post-123', function() {
 *     return "<html>Blog post content</html>";
 * });
 */
class ISROrchestrator
{
    private RequestClassifier $classifier;
    private CacheKeyGenerator $keyGenerator;
    private FileCacheStore $cacheStore;
    private FreshnessCalculator $freshnessCalculator;
    private BackgroundDispatcher $backgroundDispatcher;
    private ContentGenerator $contentGenerator;
    private ResponseSender $responseSender;
    private StatsCollector $statsCollector;
    private LockManager $lockManager;
    private Logger $logger;
    private ConfigManager $config;

    /**
     * CallbackRegistry for background jobs (assumes we have this component)
     *
     * @var object|null CallbackRegistry instance
     */
    private ?object $callbackRegistry;

    /**
     * Create a new ISR Orchestrator
     *
     * @param ConfigManager|null $config Configuration manager
     * @param object|null $callbackRegistry CallbackRegistry for background jobs
     */
    public function __construct(
        ?ConfigManager $config = null,
        ?object $callbackRegistry = null,
    ) {
        // Initialize configuration
        $this->config = $config ?? new ConfigManager();

        // Initialize all components
        $this->classifier = new RequestClassifier();
        $this->keyGenerator = new CacheKeyGenerator();

        $this->cacheStore = new FileCacheStore(
            $this->config->getCacheDir(),
            $this->config->getDefaultTTL(),
            $this->config->useSharding(),
        );

        $this->freshnessCalculator = new FreshnessCalculator(
            $this->config->getStaleWindowSeconds(),
        );

        $this->contentGenerator = new ContentGenerator();
        $this->responseSender = new ResponseSender();

        $this->statsCollector = $this->config->isStatsEnabled()
            ? new StatsCollector()
            : new NullStatsCollector();

        $this->lockManager = new LockManager(
            $this->config->getCacheDir() . "/locks",
            $this->config->getBackgroundTimeout(),
        );

        $this->logger = new Logger(
            "file",
            $this->config->getString("logging.level", "info"),
            ["path" => $this->config->getCacheDir() . "/isr.log"],
        );

        $this->callbackRegistry = $callbackRegistry;

        // Create ISR-specific background handler
        $isrHandler = new ISRBackgroundHandler(
            $this->contentGenerator,
            $this->cacheStore,
            $this->callbackRegistry,
            $this->statsCollector,
            $this->logger,
            $this->lockManager,
            $this->config,
        );

        // Initialize background dispatcher with ISR handler
        $this->backgroundDispatcher = new BackgroundDispatcher($isrHandler);

        $this->logger->info("ISR Orchestrator initialized", [
            "cache_dir" => $this->config->getCacheDir(),
            "default_ttl" => $this->config->getDefaultTTL(),
            "stale_window" => $this->config->getStaleWindowSeconds(),
            "background_handler" => "ISRBackgroundHandler",
        ]);
    }

    /**
     * Handle an HTTP request with ISR pattern
     *
     * This is the main entry point for the ISR system.
     *
     * @param string|callable $urlOrCallback URL or callback for content generation
     * @param callable|null $callback Content generation callback (if URL provided)
     * @param array $options Optional configuration overrides
     * @return array Result with sent status and metadata
     */
    public function handleRequest(
        $urlOrCallback,
        ?callable $callback = null,
        array $options = [],
    ): array {
        $startTime = microtime(true);

        // Parse arguments (flexible API)
        if (is_callable($urlOrCallback)) {
            // Called as: handleRequest(function() { ... })
            $callback = $urlOrCallback;
            $url = $this->extractUrlFromRequest();
        } else {
            // Called as: handleRequest('/page', function() { ... })
            $url = $urlOrCallback;
        }

        // Extract request context
        $requestContext = $this->extractRequestContext();

        $this->logger->debug("Handling request", [
            "url" => $url,
            "method" => $requestContext["method"],
        ]);

        // Step 1: Classify request (is it cacheable?)
        $classification = $this->classifier->classify($requestContext);

        if (!$classification["cacheable"]) {
            $this->logger->debug("Request not cacheable", [
                "reason" => $classification["reason"],
                "rule" => $classification["rule_triggered"],
            ]);

            return $this->handleNonCacheableRequest(
                $callback,
                $url,
                $options,
                $startTime,
            );
        }

        // Step 2: Generate cache key
        $variants = $options["variants"] ?? [];
        $cacheKey = $this->keyGenerator->generate([
            "url" => $url,
            "variants" => $variants,
        ]);

        $this->logger->debug("Cache key generated", [
            "url" => $url,
            "cache_key" => $cacheKey,
        ]);

        // Step 3: Check cache
        $cachedEntry = $this->cacheStore->read($cacheKey);

        if ($cachedEntry === null) {
            $this->logger->debug("Cache miss", ["cache_key" => $cacheKey]);
            $this->statsCollector->record(["event" => "cache_miss"]);

            return $this->handleCacheMiss(
                $callback,
                $url,
                $cacheKey,
                $options,
                $startTime,
            );
        }

        // Step 4: Check freshness
        $freshness = $this->freshnessCalculator->calculate([
            "created_at" => $cachedEntry["created_at"],
            "ttl" => $cachedEntry["ttl"],
        ]);

        $status = $freshness["status"];
        $this->logger->debug("Cache hit", [
            "cache_key" => $cacheKey,
            "status" => $status,
            "age" => $freshness["age_seconds"] . "s",
        ]);

        switch ($status) {
            case "fresh":
                $this->statsCollector->record(["event" => "cache_hit"]);
                return $this->serveCachedContent(
                    $cachedEntry,
                    $cacheKey,
                    $startTime,
                );

            case "stale":
                $this->statsCollector->record(["event" => "stale_serve"]);
                return $this->handleStaleContent(
                    $cachedEntry,
                    $callback,
                    $url,
                    $cacheKey,
                    $options,
                    $startTime,
                );

            case "expired":
                $this->statsCollector->record(["event" => "cache_miss"]);
                return $this->handleCacheMiss(
                    $callback,
                    $url,
                    $cacheKey,
                    $options,
                    $startTime,
                );
        }

        // Should never reach here
        return ["error" => "Unknown freshness status: {$status}"];
    }

    /**
     * Handle a non-cacheable request
     *
     * Generate content synchronously and send without caching.
     *
     * @param callable $callback Content generator
     * @param string $url Request URL
     * @param array $options Options
     * @param float $startTime Request start time
     * @return array Result
     */
    private function handleNonCacheableRequest(
        callable $callback,
        string $url,
        array $options,
        float $startTime,
    ): array {
        // Generate content
        $result = $this->contentGenerator->execute([
            "generator" => $callback,
            "timeout" => $options["timeout"] ?? null,
            "url" => $url,
        ]);

        if (!$result["success"]) {
            $this->logger->error("Content generation failed", [
                "url" => $url,
                "error" => $result["error"],
            ]);

            return $this->sendErrorResponse($result["error"], 500);
        }

        // Send response
        $sendResult = $this->responseSender->send([
            "html" => $result["html"],
            "status_code" => 200,
            "headers" => ["X-ISR-Cache" => "bypass"],
            "compress" => $this->config->isCompressionEnabled(),
        ]);

        $totalTime = (microtime(true) - $startTime) * 1000;

        $this->logger->info("Non-cacheable request served", [
            "url" => $url,
            "generation_time_ms" => $result["generation_time_ms"],
            "total_time_ms" => $totalTime,
        ]);

        return array_merge($sendResult, [
            "cache_status" => "bypass",
            "generation_time_ms" => $result["generation_time_ms"],
            "total_time_ms" => $totalTime,
        ]);
    }

    /**
     * Handle cache miss
     *
     * Generate content synchronously, store in cache, and send response.
     *
     * @param callable $callback Content generator
     * @param string $url Request URL
     * @param string $cacheKey Cache key
     * @param array $options Options
     * @param float $startTime Request start time
     * @return array Result
     */
    private function handleCacheMiss(
        callable $callback,
        string $url,
        string $cacheKey,
        array $options,
        float $startTime,
    ): array {
        // Check if another process is regenerating
        if ($this->lockManager->isLocked($cacheKey)) {
            $this->logger->debug("Waiting for lock", [
                "cache_key" => $cacheKey,
            ]);

            // Wait briefly for the other process
            $lockResult = $this->lockManager->acquireWithWait(
                $cacheKey,
                $this->config->getBackgroundTimeout(),
                5, // Wait max 5 seconds
                100, // Check every 100ms
            );

            if (!$lockResult["locked"]) {
                // Another process is still regenerating, serve stale if available
                $this->logger->debug(
                    "Lock timeout, checking for stale content",
                );

                // Re-check cache (might have been updated)
                $cachedEntry = $this->cacheStore->read($cacheKey);
                if ($cachedEntry !== null) {
                    return $this->serveCachedContent(
                        $cachedEntry,
                        $cacheKey,
                        $startTime,
                        "locked",
                    );
                }

                // No stale content, generate anyway
                $this->logger->warning(
                    "No stale content available, generating despite lock",
                );
            }
        } else {
            // Acquire lock
            $lockResult = $this->lockManager->process([
                "key" => $cacheKey,
                "action" => "acquire",
                "timeout" => $this->config->getBackgroundTimeout(),
            ]);

            if (!$lockResult["locked"]) {
                $this->logger->warning("Failed to acquire lock", [
                    "cache_key" => $cacheKey,
                ]);
            }
        }

        // Generate content
        $result = $this->contentGenerator->execute([
            "generator" => $callback,
            "timeout" => $options["timeout"] ?? null,
            "url" => $url,
        ]);

        // Release lock
        $this->lockManager->process([
            "key" => $cacheKey,
            "action" => "release",
        ]);

        if (!$result["success"]) {
            $this->logger->error("Content generation failed", [
                "url" => $url,
                "error" => $result["error"],
            ]);

            return $this->sendErrorResponse($result["error"], 500);
        }

        // Store in cache
        $ttl = $options["ttl"] ?? $this->config->getDefaultTTL();
        $metadata = [
            "url" => $url,
            "variants" => $options["variants"] ?? [],
            "generated_at" => time(),
        ];

        $this->cacheStore->write($cacheKey, $result["html"], $ttl, $metadata);

        $this->logger->debug("Content cached", [
            "cache_key" => $cacheKey,
            "ttl" => $ttl,
            "size" => strlen($result["html"]),
        ]);

        // Record stats
        $this->statsCollector->record([
            "event" => "generation",
            "metadata" => ["duration" => $result["generation_time_ms"] / 1000],
        ]);

        // Send response
        $sendResult = $this->responseSender->send([
            "html" => $result["html"],
            "status_code" => 200,
            "headers" => [
                "X-ISR-Cache" => "miss",
                "X-ISR-Generation-Time" => $result["generation_time_ms"] . "ms",
            ],
            "compress" => $this->config->isCompressionEnabled(),
        ]);

        $totalTime = (microtime(true) - $startTime) * 1000;

        $this->logger->info("Cache miss served", [
            "url" => $url,
            "cache_key" => $cacheKey,
            "generation_time_ms" => $result["generation_time_ms"],
            "total_time_ms" => $totalTime,
        ]);

        return array_merge($sendResult, [
            "cache_status" => "miss",
            "generation_time_ms" => $result["generation_time_ms"],
            "total_time_ms" => $totalTime,
        ]);
    }

    /**
     * Handle stale content (ISR pattern)
     *
     * Serve stale content immediately and queue background regeneration.
     *
     * @param array $cachedEntry Cached content entry
     * @param callable $callback Content generator
     * @param string $url Request URL
     * @param string $cacheKey Cache key
     * @param array $options Options
     * @param float $startTime Request start time
     * @return array Result
     */
    private function handleStaleContent(
        array $cachedEntry,
        callable $callback,
        string $url,
        string $cacheKey,
        array $options,
        float $startTime,
    ): array {
        // Check if already being regenerated
        if ($this->lockManager->isLocked($cacheKey)) {
            $this->logger->debug("Regeneration already in progress", [
                "cache_key" => $cacheKey,
            ]);

            return $this->serveCachedContent(
                $cachedEntry,
                $cacheKey,
                $startTime,
                "stale-regenerating",
            );
        }

        // Queue background regeneration
        $this->queueBackgroundRegeneration(
            $url,
            $cacheKey,
            $callback,
            $options,
        );

        // Serve stale content immediately
        return $this->serveCachedContent(
            $cachedEntry,
            $cacheKey,
            $startTime,
            "stale",
        );
    }

    /**
     * Queue background regeneration job
     *
     * @param string $url Request URL
     * @param string $cacheKey Cache key
     * @param callable $callback Content generator
     * @param array $options Options
     */
    private function queueBackgroundRegeneration(
        string $url,
        string $cacheKey,
        callable $callback,
        array $options,
    ): void {
        // Determine callback name
        $callbackName = $options["callback_name"] ?? null;

        // If callback registry is available and callback is registered, use it
        if (
            $callbackName &&
            $this->callbackRegistry &&
            method_exists($this->callbackRegistry, "has") &&
            $this->callbackRegistry->has($callbackName)
        ) {
            $this->logger->debug(
                "Using registered callback for background job",
                [
                    "callback_name" => $callbackName,
                ],
            );

            $jobParams = [
                "url" => $url,
                "cache_key" => $cacheKey,
                "callback_name" => $callbackName,
                "callback_params" => $options["callback_params"] ?? [],
                "ttl" => $options["ttl"] ?? $this->config->getDefaultTTL(),
                "variants" => $options["variants"] ?? [],
            ];
        } else {
            // Fallback: serialize callback (won't work in all cases)
            $this->logger->warning(
                "Callback not registered, background regeneration may fail",
                ["url" => $url],
            );

            $jobParams = [
                "url" => $url,
                "cache_key" => $cacheKey,
                "callback" => $callback, // This may not serialize!
                "ttl" => $options["ttl"] ?? $this->config->getDefaultTTL(),
                "variants" => $options["variants"] ?? [],
            ];
        }

        // Dispatch job
        $result = $this->backgroundDispatcher->dispatch([
            "task" => "regenerate",
            "params" => $jobParams,
        ]);

        $this->logger->info("Background regeneration queued", [
            "url" => $url,
            "cache_key" => $cacheKey,
            "job_id" => $result["job_id"],
            "method" => $result["method_used"],
        ]);
    }

    /**
     * Serve cached content
     *
     * @param array $cachedEntry Cached content entry
     * @param string $cacheKey Cache key
     * @param float $startTime Request start time
     * @param string $status Cache status (fresh/stale/stale-regenerating)
     * @return array Result
     */
    private function serveCachedContent(
        array $cachedEntry,
        string $cacheKey,
        float $startTime,
        string $status = "fresh",
    ): array {
        $content = $cachedEntry["content"];
        $age = time() - $cachedEntry["created_at"];

        // Send response
        $sendResult = $this->responseSender->send([
            "html" => $content,
            "status_code" => 200,
            "headers" => [
                "X-ISR-Cache" => $status,
                "X-ISR-Age" => $age . "s",
                "Cache-Control" => "public, max-age=" . $cachedEntry["ttl"],
            ],
            "compress" => $this->config->isCompressionEnabled(),
        ]);

        $totalTime = (microtime(true) - $startTime) * 1000;

        $this->logger->info("Cached content served", [
            "cache_key" => $cacheKey,
            "status" => $status,
            "age" => $age . "s",
            "total_time_ms" => $totalTime,
        ]);

        return array_merge($sendResult, [
            "cache_status" => $status,
            "age_seconds" => $age,
            "total_time_ms" => $totalTime,
        ]);
    }

    /**
     * Send error response
     *
     * @param string $error Error message
     * @param int $statusCode HTTP status code
     * @return array Result
     */
    private function sendErrorResponse(string $error, int $statusCode): array
    {
        $html =
            "<html><body><h1>Error</h1><p>" .
            htmlspecialchars($error) .
            "</p></body></html>";

        return $this->responseSender->send([
            "html" => $html,
            "status_code" => $statusCode,
            "headers" => ["X-ISR-Error" => "true"],
            "compress" => false,
        ]);
    }

    /**
     * Extract request context from PHP superglobals
     *
     * @return array Request context for RequestClassifier
     */
    private function extractRequestContext(): array
    {
        return [
            "method" => $_SERVER["REQUEST_METHOD"] ?? "GET",
            "url" => $_SERVER["REQUEST_URI"] ?? "/",
            "cookies" => $_COOKIE ?? [],
            "query" => $_GET ?? [],
            "headers" => $this->getAllHeaders(),
            "post" => $_POST ?? [],
        ];
    }

    /**
     * Extract URL from current request
     *
     * @return string Request URL
     */
    private function extractUrlFromRequest(): string
    {
        return $_SERVER["REQUEST_URI"] ?? "/";
    }

    /**
     * Get all HTTP headers (cross-platform)
     *
     * @return array Headers
     */
    private function getAllHeaders(): array
    {
        if (function_exists("getallheaders")) {
            return getallheaders() ?: [];
        }

        // Fallback for nginx
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === "HTTP_") {
                $headerName = str_replace(
                    " ",
                    "-",
                    ucwords(
                        strtolower(str_replace("_", " ", substr($name, 5))),
                    ),
                );
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    /**
     * Invalidate cache entries based on event
     *
     * Public API for cache invalidation.
     *
     * @param array $event Event data (post updated, etc.)
     * @return array Invalidation result
     */
    public function invalidate(array $event): array
    {
        require_once __DIR__ . "/InvalidationResolver.php";
        require_once __DIR__ . "/CachePurger.php";

        $resolver = new InvalidationResolver($this->keyGenerator);
        $purger = new CachePurger($this->config->getCacheDir());

        // Resolve which keys to purge
        $resolution = $resolver->resolve($event);

        if (empty($resolution["cache_keys_to_purge"])) {
            $this->logger->debug("No cache keys to purge", [
                "event" => $event["event"] ?? "unknown",
            ]);

            return [
                "purged" => 0,
                "reason" => $resolution["reason"],
            ];
        }

        // Purge cache entries
        $purgeResult = $purger->purge([
            "keys" => $resolution["cache_keys_to_purge"],
        ]);

        $this->logger->info("Cache invalidated", [
            "event" => $event["event"] ?? "unknown",
            "keys_purged" => $purgeResult["purged_count"],
            "reason" => $resolution["reason"],
        ]);

        return [
            "purged" => $purgeResult["purged_count"],
            "keys" => $purgeResult["keys_purged"],
            "reason" => $resolution["reason"],
        ];
    }

    /**
     * Get current statistics
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        return $this->statsCollector->getStats();
    }

    /**
     * Get health status
     *
     * @return array Health check results
     */
    public function getHealth(): array
    {
        require_once __DIR__ . "/HealthMonitor.php";

        $monitor = new HealthMonitor([
            "min_disk_space" => 1073741824, // 1GB
            "min_php_version" => "7.4.0",
            "min_memory" => 67108864, // 64MB
        ]);

        return $monitor->check([
            "cache_dir" => $this->config->getCacheDir(),
        ]);
    }

    /**
     * Cleanup expired locks and cache entries
     *
     * Should be called periodically (e.g., via cron).
     *
     * @return array Cleanup results
     */
    public function cleanup(): array
    {
        $locksRemoved = $this->lockManager->cleanupExpiredLocks();
        $cacheRemoved = $this->cacheStore->prune();

        $this->logger->info("Cleanup completed", [
            "locks_removed" => $locksRemoved,
            "cache_entries_removed" => $cacheRemoved,
        ]);

        return [
            "locks_removed" => $locksRemoved,
            "cache_entries_removed" => $cacheRemoved,
        ];
    }

    /**
     * Get the callback registry
     *
     * @return object|null CallbackRegistry instance
     */
    public function getCallbackRegistry(): ?object
    {
        return $this->callbackRegistry;
    }

    /**
     * Set the callback registry
     *
     * @param object $registry CallbackRegistry instance
     */
    public function setCallbackRegistry(object $registry): void
    {
        $this->callbackRegistry = $registry;
    }
}

/**
 * Null Stats Collector
 *
 * No-op implementation when stats are disabled
 */
class NullStatsCollector
{
    /**
     * Record an event (no-op)
     *
     * @param array $event Event data
     * @return array Empty result
     */
    public function record(array $event): array
    {
        return ["recorded" => false, "current_stats" => []];
    }

    /**
     * Get statistics (no-op)
     *
     * @return array Empty stats
     */
    public function getStats(): array
    {
        return [];
    }
}
