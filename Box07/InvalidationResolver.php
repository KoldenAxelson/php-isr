<?php

declare(strict_types=1);

require_once __DIR__ . "/../Box02/CacheKeyGenerator.php";

/**
 * InvalidationResolver
 *
 * Determines which cache keys should be invalidated when content changes.
 * Maps events (post updated, comment added) to affected pages and their cache keys.
 * Designed for speed and simplicity while handling complex dependency graphs.
 */
class InvalidationResolver
{
    private CacheKeyGenerator $keyGenerator;

    /**
     * Invalidation rules mapping event types to affected page patterns
     *
     * @var array<string, array<string>>
     */
    private array $rules;

    /**
     * @param CacheKeyGenerator|null $keyGenerator Optional key generator instance
     */
    public function __construct(?CacheKeyGenerator $keyGenerator = null)
    {
        $this->keyGenerator = $keyGenerator ?? new CacheKeyGenerator();
        $this->initializeDefaultRules();
    }

    /**
     * Initialize default invalidation rules for common CMS events
     *
     * Note: Comment events require 'post_page' dependency to invalidate the post.
     * Example: ['dependencies' => ['post_page' => [$postId]]]
     */
    private function initializeDefaultRules(): void
    {
        $this->rules = [
            "post_created" => [
                "homepage",
                "category_page",
                "author_page",
                "tag_pages",
                "archive_page",
                "rss_feed",
            ],
            "post_updated" => [
                "post_page",
                "homepage",
                "category_page",
                "author_page",
                "tag_pages",
            ],
            "post_deleted" => [
                "post_page",
                "homepage",
                "category_page",
                "author_page",
                "tag_pages",
                "archive_page",
            ],
            // Comment events: Requires 'post_page' dependency to specify which post
            "comment_added" => [
                "post_page", // Requires: ['post_page' => [$postId]]
                "recent_comments",
            ],
            "comment_updated" => [
                "post_page", // Requires: ['post_page' => [$postId]]
            ],
            "comment_deleted" => [
                "post_page", // Requires: ['post_page' => [$postId]]
                "recent_comments",
            ],
            "user_updated" => ["author_page"],
            "category_updated" => ["category_page", "homepage"],
        ];
    }

    /**
     * Resolve which cache keys to invalidate for a given event
     *
     * @param array $event Event data with type, entity info, and dependencies
     * @return array Result with cache keys to purge and reason
     */
    public function resolve(array $event): array
    {
        $eventType = $event["event"] ?? "";
        $entityId = $event["entity_id"] ?? null;
        $entityType = $event["entity_type"] ?? "";
        $dependencies = $event["dependencies"] ?? [];
        $variants = $event["variants"] ?? [];

        // Get affected page types from rules
        $affectedPageTypes = $this->rules[$eventType] ?? [];

        if (empty($affectedPageTypes)) {
            return [
                "cache_keys_to_purge" => [],
                "reason" => "No invalidation rules defined for event: {$eventType}",
            ];
        }

        // Generate URLs for affected pages
        $urlsToInvalidate = $this->generateAffectedUrls(
            $affectedPageTypes,
            $entityType,
            $entityId,
            $dependencies,
        );

        // Generate cache keys (with variants if provided)
        $cacheKeys = $this->generateCacheKeys($urlsToInvalidate, $variants);

        // Build reason string
        $pageCount = count($cacheKeys);
        $reason = $this->buildReason(
            $eventType,
            $entityType,
            $entityId,
            $pageCount,
        );

        return [
            "cache_keys_to_purge" => $cacheKeys,
            "reason" => $reason,
        ];
    }

    /**
     * Resolve invalidations for multiple events in batch
     *
     * @param array $events Array of event data
     * @return array Array of resolution results
     */
    public function resolveBatch(array $events): array
    {
        $results = [];
        foreach ($events as $index => $event) {
            $results[$index] = $this->resolve($event);
        }
        return $results;
    }

    /**
     * Generate URLs for all affected pages based on event and dependencies
     *
     * @param array $pageTypes Types of pages affected (homepage, category_page, etc.)
     * @param string $entityType Type of entity (post, comment, etc.)
     * @param mixed $entityId ID of the affected entity
     * @param array $dependencies Additional dependencies (categories, authors, tags)
     * @return array List of URLs to invalidate
     */
    private function generateAffectedUrls(
        array $pageTypes,
        string $entityType,
        $entityId,
        array $dependencies,
    ): array {
        $urls = [];

        foreach ($pageTypes as $pageType) {
            switch ($pageType) {
                case "post_page":
                    // First check if it's the primary entity
                    if ($entityType === "post" && $entityId !== null) {
                        $urls[] = "/blog/post-{$entityId}";
                    }
                    // Also check if post IDs are provided in dependencies
                    if (isset($dependencies["post_page"])) {
                        foreach (
                            (array) $dependencies["post_page"]
                            as $postId
                        ) {
                            $urls[] = "/blog/post-{$postId}";
                        }
                    }
                    break;

                case "homepage":
                    $urls[] = "/";
                    if (isset($dependencies["homepage"])) {
                        // Handle homepage variants (e.g., latest_posts section)
                        foreach (
                            (array) $dependencies["homepage"]
                            as $section
                        ) {
                            $urls[] = "/?section={$section}";
                        }
                    }
                    break;

                case "category_page":
                    if (isset($dependencies["category_page"])) {
                        foreach (
                            (array) $dependencies["category_page"]
                            as $category
                        ) {
                            $urls[] = "/category/{$category}";
                        }
                    }
                    break;

                case "author_page":
                    if (isset($dependencies["author_page"])) {
                        foreach (
                            (array) $dependencies["author_page"]
                            as $author
                        ) {
                            $urls[] = "/author/{$author}";
                        }
                    }
                    break;

                case "tag_pages":
                    if (isset($dependencies["tag_pages"])) {
                        foreach ((array) $dependencies["tag_pages"] as $tag) {
                            $urls[] = "/tag/{$tag}";
                        }
                    }
                    break;

                case "archive_page":
                    if (isset($dependencies["archive_page"])) {
                        foreach (
                            (array) $dependencies["archive_page"]
                            as $archive
                        ) {
                            $urls[] = "/archive/{$archive}";
                        }
                    }
                    break;

                case "recent_comments":
                    $urls[] = "/comments/recent";
                    break;

                case "rss_feed":
                    $urls[] = "/feed.xml";
                    break;
            }
        }

        return array_unique($urls);
    }

    /**
     * Generate cache keys for list of URLs with optional variants
     *
     * @param array $urls List of URLs
     * @param array $variantsList Array of variant sets to generate keys for
     * @return array List of unique cache keys
     */
    private function generateCacheKeys(
        array $urls,
        array $variantsList = [],
    ): array {
        $keys = [];

        // If no variants specified, generate single key per URL
        if (empty($variantsList)) {
            $variantsList = [[]];
        }

        foreach ($urls as $url) {
            foreach ($variantsList as $variants) {
                $input = [
                    "url" => $url,
                    "variants" => $variants,
                ];
                $keys[] = $this->keyGenerator->generate($input);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Build human-readable reason string
     *
     * @param string $eventType Type of event
     * @param string $entityType Type of entity
     * @param mixed $entityId Entity ID
     * @param int $pageCount Number of affected pages
     * @return string Formatted reason
     */
    private function buildReason(
        string $eventType,
        string $entityType,
        $entityId,
        int $pageCount,
    ): string {
        $action = str_replace("_", " ", $eventType);
        $entity =
            $entityId !== null ? "{$entityType} {$entityId}" : $entityType;

        return ucfirst($action) . " affects {$pageCount} page(s)";
    }

    /**
     * Add or update custom invalidation rule
     *
     * @param string $eventType Event type identifier
     * @param array $affectedPageTypes List of page types this event affects
     */
    public function addRule(string $eventType, array $affectedPageTypes): void
    {
        $this->rules[$eventType] = $affectedPageTypes;
    }

    /**
     * Get all defined rules
     *
     * @return array All invalidation rules
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get affected page types for a specific event
     *
     * @param string $eventType Event type
     * @return array List of affected page types
     */
    public function getAffectedPageTypes(string $eventType): array
    {
        return $this->rules[$eventType] ?? [];
    }

    /**
     * Estimate number of cache keys that will be invalidated
     * (without actually generating them)
     *
     * @param array $event Event data
     * @return int Estimated number of keys
     */
    public function estimateInvalidationCount(array $event): int
    {
        $eventType = $event["event"] ?? "";
        $dependencies = $event["dependencies"] ?? [];
        $variants = $event["variants"] ?? [];

        $affectedPageTypes = $this->rules[$eventType] ?? [];
        $urlCount = 0;

        foreach ($affectedPageTypes as $pageType) {
            switch ($pageType) {
                case "homepage":
                case "recent_comments":
                case "rss_feed":
                    $urlCount += 1;
                    break;

                case "post_page":
                    // Only count primary entity if it's actually a post
                    $entityType = $event["entity_type"] ?? "";
                    if ($entityType === "post") {
                        $urlCount += 1; // Primary post entity
                    }
                    // Count any additional post_page dependencies
                    $urlCount += count($dependencies["post_page"] ?? []);
                    break;

                case "category_page":
                    $urlCount += count($dependencies["category_page"] ?? []);
                    break;

                case "author_page":
                    $urlCount += count($dependencies["author_page"] ?? []);
                    break;

                case "tag_pages":
                    $urlCount += count($dependencies["tag_pages"] ?? []);
                    break;

                case "archive_page":
                    $urlCount += count($dependencies["archive_page"] ?? []);
                    break;
            }
        }

        $variantCount = empty($variants) ? 1 : count($variants);
        return $urlCount * $variantCount;
    }
}
