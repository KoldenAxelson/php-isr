<?php

/**
 * ISR Configuration Example (PHP)
 *
 * This file demonstrates all available configuration options
 * for the ISR (Incremental Static Regeneration) library.
 *
 * Copy this file to config.php and customize for your needs.
 */

return [
    /**
     * Cache Configuration
     */
    "cache" => [
        // Directory for cache files
        "dir" => "/var/www/cache/isr",

        // Default TTL in seconds (3600 = 1 hour)
        "default_ttl" => 3600,

        // Use 2-level directory sharding for many files
        // Recommended for >10,000 cached pages
        "use_sharding" => false,
    ],

    /**
     * Freshness Configuration
     */
    "freshness" => [
        // Stale window in seconds (time after TTL before expiration)
        // null = use TTL value (default ISR behavior)
        // 0 = no stale window (fresh→expired immediately)
        // >0 = custom stale window
        "stale_window_seconds" => null,
    ],

    /**
     * Background Job Configuration
     */
    "background" => [
        // Timeout for background jobs in seconds
        "timeout" => 30,

        // Maximum retry attempts for failed jobs
        "max_retries" => 3,
    ],

    /**
     * Statistics Configuration
     */
    "stats" => [
        // Enable statistics collection
        "enabled" => true,

        // File to persist stats (null = memory only)
        "file" => null,
    ],

    /**
     * Compression Configuration
     */
    "compression" => [
        // Enable gzip compression for cached content
        "enabled" => false,

        // Compression level (1-9, higher = better compression, slower)
        "level" => 6,
    ],

    /**
     * Custom Application Settings
     *
     * You can add your own configuration sections here
     */
    "app" => [
        "name" => "My ISR Application",
        "environment" => "production",
        "debug" => false,
    ],
];
