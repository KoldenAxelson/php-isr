<?php

/**
 * ISR Orchestrator - Usage Examples
 *
 * This file demonstrates various ways to use the ISR Orchestrator
 */

require_once __DIR__ . "/ISROrchestrator.php";
require_once __DIR__ . "/ConfigManager.php";
require_once __DIR__ . "/CallbackRegistry.php"; // Assuming this will be implemented

// ============================================================================
// EXAMPLE 1: Basic Usage (Simple Blog Post)
// ============================================================================

function example1_basic_usage()
{
    // Initialize orchestrator with default config
    $orchestrator = new ISROrchestrator();

    // Handle a request with inline callback
    $orchestrator->handleRequest("/blog/post-123", function () {
        // Your content generation logic
        return "<html>
            <head><title>My Blog Post</title></head>
            <body>
                <h1>Blog Post #123</h1>
                <p>This content was generated at " .
            date("Y-m-d H:i:s") .
            "</p>
            </body>
        </html>";
    });

    // That's it! The orchestrator handles:
    // - Cache checking
    // - Freshness calculation
    // - Stale-while-revalidate pattern
    // - Background regeneration
    // - Response sending
}

// ============================================================================
// EXAMPLE 2: With Custom Configuration
// ============================================================================

function example2_custom_config()
{
    // Load configuration
    $config = ConfigManager::fromFile(__DIR__ . "/config.php");

    // Or from environment variables
    $config = ConfigManager::fromEnv("ISR_");

    // Or from array
    $config = new ConfigManager([
        "cache" => [
            "dir" => "/var/www/cache/isr",
            "default_ttl" => 3600, // 1 hour
            "use_sharding" => true,
        ],
        "freshness" => [
            "stale_window_seconds" => 1800, // 30 minutes stale window
        ],
        "compression" => [
            "enabled" => true,
            "level" => 6,
        ],
    ]);

    // Validate configuration
    $validation = $config->validate();
    if (!$validation["valid"]) {
        die("Invalid config: " . implode(", ", $validation["errors"]));
    }

    // Initialize orchestrator
    $orchestrator = new ISROrchestrator($config);

    // Handle request
    $orchestrator->handleRequest("/page", function () {
        return "<html>Page content</html>";
    });
}

// ============================================================================
// EXAMPLE 3: With Callback Registry (Recommended for Production)
// ============================================================================

function example3_callback_registry()
{
    // Initialize callback registry
    $registry = new CallbackRegistry();

    // Register your content generators
    $registry->register("homepage", function () {
        return "<html><body><h1>Welcome to my site!</h1></body></html>";
    });

    $registry->register("blog_post", function ($params) {
        $postId = $params["post_id"];
        // Fetch post from database
        $post = fetchPostFromDb($postId);
        return renderPostTemplate($post);
    });

    $registry->register("category_page", function ($params) {
        $category = $params["category"];
        $posts = fetchPostsByCategory($category);
        return renderCategoryTemplate($category, $posts);
    });

    // Initialize orchestrator with registry
    $orchestrator = new ISROrchestrator(null, $registry);

    // Handle requests using registered callbacks
    $orchestrator->handleRequest("/", null, [
        "callback_name" => "homepage",
        "ttl" => 3600,
    ]);

    $orchestrator->handleRequest("/blog/post-123", null, [
        "callback_name" => "blog_post",
        "callback_params" => ["post_id" => 123],
        "ttl" => 7200, // 2 hours
    ]);
}

// ============================================================================
// EXAMPLE 4: WordPress Integration
// ============================================================================

function example4_wordpress_integration()
{
    // In your WordPress plugin or theme:

    $registry = new CallbackRegistry();

    // Register WordPress content generators
    $registry->register("homepage", function () {
        ob_start();
        get_header();
        get_template_part("homepage");
        get_footer();
        return ob_get_clean();
    });

    $registry->register("single_post", function ($params) {
        $postId = $params["post_id"];
        global $post;
        $post = get_post($postId);
        setup_postdata($post);

        ob_start();
        get_header();
        get_template_part("content", "single");
        get_footer();
        wp_reset_postdata();

        return ob_get_clean();
    });

    $registry->register("archive", function ($params) {
        $category = $params["category"];
        $query = new WP_Query(["category_name" => $category]);

        ob_start();
        get_header();
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                get_template_part("content", "archive");
            }
        }
        get_footer();
        wp_reset_postdata();

        return ob_get_clean();
    });

    // Initialize orchestrator
    $config = new ConfigManager([
        "cache" => [
            "dir" => WP_CONTENT_DIR . "/cache/isr",
            "default_ttl" => 3600,
        ],
    ]);

    $orchestrator = new ISROrchestrator($config, $registry);

    // Hook into WordPress request
    add_action("template_redirect", function () use ($orchestrator) {
        // Determine callback based on WordPress query
        if (is_front_page()) {
            $orchestrator->handleRequest("/", null, [
                "callback_name" => "homepage",
                "variants" => ["lang" => get_locale()],
            ]);
            exit();
        } elseif (is_single()) {
            global $post;
            $orchestrator->handleRequest(get_permalink(), null, [
                "callback_name" => "single_post",
                "callback_params" => ["post_id" => $post->ID],
                "variants" => ["lang" => get_locale()],
            ]);
            exit();
        }
        // Let WordPress handle other requests normally
    });

    // Hook into post updates for cache invalidation
    add_action("save_post", function ($postId) use ($orchestrator) {
        $post = get_post($postId);

        $orchestrator->invalidate([
            "event" => "post_updated",
            "entity_type" => "post",
            "entity_id" => $postId,
            "dependencies" => [
                "category_page" => wp_get_post_categories($postId, [
                    "fields" => "slugs",
                ]),
                "author_page" => [
                    get_the_author_meta("login", $post->post_author),
                ],
                "tag_pages" => wp_get_post_tags($postId, ["fields" => "slugs"]),
            ],
        ]);
    });
}

// ============================================================================
// EXAMPLE 5: Laravel Integration
// ============================================================================

function example5_laravel_integration()
{
    // In a service provider or middleware:

    $registry = new CallbackRegistry();

    // Register Laravel views as callbacks
    $registry->register("homepage", function () {
        return view("pages.home")->render();
    });

    $registry->register("blog_post", function ($params) {
        $slug = $params["slug"];
        $post = App\Models\Post::where("slug", $slug)->firstOrFail();
        return view("posts.show", ["post" => $post])->render();
    });

    $registry->register("category", function ($params) {
        $category = $params["category"];
        $posts = App\Models\Post::where("category", $category)->get();
        return view("categories.show", [
            "category" => $category,
            "posts" => $posts,
        ])->render();
    });

    // Create middleware
    class ISRMiddleware
    {
        private $orchestrator;

        public function __construct()
        {
            $config = new ConfigManager([
                "cache" => [
                    "dir" => storage_path("cache/isr"),
                    "default_ttl" => 3600,
                ],
            ]);

            $this->orchestrator = new ISROrchestrator(
                $config,
                app(CallbackRegistry::class),
            );
        }

        public function handle($request, Closure $next)
        {
            // Only cache GET requests
            if ($request->method() !== "GET") {
                return $next($request);
            }

            // Determine callback based on route
            $route = $request->route();
            if (!$route) {
                return $next($request);
            }

            $callbackName = $this->getCallbackForRoute($route);
            if (!$callbackName) {
                return $next($request);
            }

            // Use ISR
            $this->orchestrator->handleRequest($request->fullUrl(), null, [
                "callback_name" => $callbackName,
                "callback_params" => $route->parameters(),
            ]);

            // Response already sent by orchestrator
            exit();
        }

        private function getCallbackForRoute($route)
        {
            $name = $route->getName();

            $mapping = [
                "home" => "homepage",
                "posts.show" => "blog_post",
                "categories.show" => "category",
            ];

            return $mapping[$name] ?? null;
        }
    }
}

// ============================================================================
// EXAMPLE 6: Cache Invalidation
// ============================================================================

function example6_cache_invalidation()
{
    $orchestrator = new ISROrchestrator();

    // When a blog post is updated
    $result = $orchestrator->invalidate([
        "event" => "post_updated",
        "entity_type" => "post",
        "entity_id" => 123,
        "dependencies" => [
            "category_page" => ["technology", "programming"],
            "author_page" => ["john-doe"],
            "tag_pages" => ["php", "web-development"],
        ],
    ]);

    echo "Purged {$result["purged"]} cache entries\n";
    echo "Reason: {$result["reason"]}\n";

    // When a comment is added
    $result = $orchestrator->invalidate([
        "event" => "comment_added",
        "entity_type" => "comment",
        "entity_id" => 456,
        "dependencies" => [
            "post_page" => [123], // Post ID that received the comment
        ],
    ]);

    // Purge by pattern
    require_once __DIR__ . "/CachePurger.php";
    $purger = new CachePurger("/var/www/cache/isr");

    $result = $purger->purge([
        "pattern" => "/blog/*", // Purge all blog pages
    ]);

    echo "Purged {$result["purged_count"]} entries matching pattern\n";
}

// ============================================================================
// EXAMPLE 7: Monitoring and Observability
// ============================================================================

function example7_monitoring()
{
    $orchestrator = new ISROrchestrator();

    // Get statistics
    $stats = $orchestrator->getStats();
    echo "Cache hit rate: {$stats["hit_rate"]}%\n";
    echo "Total requests: {$stats["total_requests"]}\n";
    echo "Stale serves: {$stats["stale_serves"]}\n";
    echo "Average generation time: {$stats["generation"]["avg_time"]}s\n";

    // Check health
    $health = $orchestrator->getHealth();
    if ($health["healthy"]) {
        echo "System is healthy!\n";
    } else {
        echo "System has issues:\n";
        foreach ($health["checks"] as $check => $result) {
            if ($result["status"] !== "ok") {
                echo "  - {$check}: {$result["error"]}\n";
            }
        }
    }

    // Cleanup (run periodically via cron)
    $cleanup = $orchestrator->cleanup();
    echo "Removed {$cleanup["locks_removed"]} expired locks\n";
    echo "Removed {$cleanup["cache_entries_removed"]} expired cache entries\n";
}

// ============================================================================
// EXAMPLE 8: Cache Variants (Multi-language, Mobile, etc.)
// ============================================================================

function example8_cache_variants()
{
    $orchestrator = new ISROrchestrator();

    // Detect user language and device type
    $language = $_SERVER["HTTP_ACCEPT_LANGUAGE"] ?? "en";
    $language = substr($language, 0, 2);

    $isMobile = preg_match(
        "/Mobile|Android|iPhone/i",
        $_SERVER["HTTP_USER_AGENT"] ?? "",
    );

    // Use variants for different versions of same URL
    $orchestrator->handleRequest("/page", null, [
        "callback_name" => "page",
        "variants" => [
            "lang" => $language,
            "device" => $isMobile ? "mobile" : "desktop",
        ],
    ]);

    // This creates separate cache entries:
    // - /page (lang=en, device=desktop)
    // - /page (lang=es, device=desktop)
    // - /page (lang=en, device=mobile)
    // - /page (lang=es, device=mobile)
}

// ============================================================================
// EXAMPLE 9: Custom TTL Per Page
// ============================================================================

function example9_custom_ttl()
{
    $orchestrator = new ISROrchestrator();

    // Homepage: cache for 1 hour
    $orchestrator->handleRequest("/", null, [
        "callback_name" => "homepage",
        "ttl" => 3600,
    ]);

    // Frequently updated blog post: cache for 5 minutes
    $orchestrator->handleRequest("/blog/breaking-news", null, [
        "callback_name" => "blog_post",
        "callback_params" => ["post_id" => 123],
        "ttl" => 300,
    ]);

    // Static about page: cache for 24 hours
    $orchestrator->handleRequest("/about", null, [
        "callback_name" => "about_page",
        "ttl" => 86400,
    ]);
}

// ============================================================================
// EXAMPLE 10: Error Handling
// ============================================================================

function example10_error_handling()
{
    $orchestrator = new ISROrchestrator();

    try {
        $result = $orchestrator->handleRequest("/page", function () {
            // Simulate an error
            throw new Exception("Database connection failed");
        });

        if (isset($result["error"])) {
            // Handle error
            error_log("ISR error: " . $result["error"]);

            // Serve fallback content
            echo "<html><body><h1>Temporarily unavailable</h1></body></html>";
        }
    } catch (Exception $e) {
        error_log("ISR exception: " . $e->getMessage());

        // Serve fallback
        echo "<html><body><h1>Error occurred</h1></body></html>";
    }
}

// Helper functions for examples
function fetchPostFromDb($postId)
{
    return [
        "id" => $postId,
        "title" => "Sample Post",
        "content" => "Content...",
    ];
}

function renderPostTemplate($post)
{
    return "<html><body><h1>{$post["title"]}</h1><p>{$post["content"]}</p></body></html>";
}

function fetchPostsByCategory($category)
{
    return [["id" => 1, "title" => "Post 1"], ["id" => 2, "title" => "Post 2"]];
}

function renderCategoryTemplate($category, $posts)
{
    $html = "<html><body><h1>Category: {$category}</h1><ul>";
    foreach ($posts as $post) {
        $html .= "<li>{$post["title"]}</li>";
    }
    $html .= "</ul></body></html>";
    return $html;
}
