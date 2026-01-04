<?php

/**
 * WordPress ISR (Incremental Static Regeneration) Integration
 * 
 * This example shows how to integrate CallbackRegistry with WordPress
 * for ISR-style background cache regeneration.
 */

require_once __DIR__ . '/CallbackRegistry.php';

// ============================================================================
// WordPress Plugin Integration
// ============================================================================

class WordPress_ISR_Plugin
{
    private CallbackRegistry $registry;
    private string $cacheDir;
    
    public function __construct()
    {
        $this->registry = new CallbackRegistry();
        $this->cacheDir = '/tmp/wp-isr-cache'; // In real plugin: WP_CONTENT_DIR . '/cache/isr'
        
        $this->initializeCallbacks();
    }
    
    /**
     * Initialize all content generation callbacks
     */
    private function initializeCallbacks(): void
    {
        // Homepage
        $this->registry->register('homepage', function() {
            return $this->renderHomepage();
        }, [
            'description' => 'Main homepage',
            'default_ttl' => 3600,
            'tags' => ['public', 'high-traffic']
        ]);
        
        // Single post
        $this->registry->register('single_post', function($params) {
            return $this->renderSinglePost($params['post_id']);
        }, [
            'description' => 'Individual blog post',
            'default_ttl' => 7200,
            'tags' => ['content']
        ]);
        
        // Category archive
        $this->registry->register('category_archive', function($params) {
            return $this->renderCategoryArchive($params['category_slug']);
        }, [
            'description' => 'Category archive page',
            'default_ttl' => 1800,
            'tags' => ['archive']
        ]);
        
        // Author archive
        $this->registry->register('author_archive', function($params) {
            return $this->renderAuthorArchive($params['author_id']);
        }, [
            'description' => 'Author archive page',
            'default_ttl' => 3600,
            'tags' => ['archive']
        ]);
        
        // Custom page template
        $this->registry->register('custom_page', function($params) {
            return $this->renderCustomPage($params['page_id'], $params['template']);
        }, [
            'description' => 'Custom page template',
            'default_ttl' => 7200
        ]);
    }
    
    /**
     * Render homepage (simulated)
     */
    private function renderHomepage(): string
    {
        // In real implementation:
        // ob_start();
        // get_header();
        // get_template_part('homepage');
        // get_footer();
        // return ob_get_clean();
        
        return "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>My WordPress Site</title>
</head>
<body>
    <header><h1>My WordPress Site</h1></header>
    <main>
        <h2>Latest Posts</h2>
        <article>
            <h3>Post 1</h3>
            <p>Excerpt...</p>
        </article>
        <article>
            <h3>Post 2</h3>
            <p>Excerpt...</p>
        </article>
    </main>
    <footer>&copy; 2024 My Site</footer>
</body>
</html>";
    }
    
    /**
     * Render single post (simulated)
     */
    private function renderSinglePost(int $postId): string
    {
        // In real implementation:
        // global $post;
        // $post = get_post($postId);
        // setup_postdata($post);
        // ... render template
        // wp_reset_postdata();
        
        return "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>Post {$postId}</title>
</head>
<body>
    <article>
        <h1>Post Title {$postId}</h1>
        <div class='content'>
            <p>Post content goes here...</p>
        </div>
    </article>
</body>
</html>";
    }
    
    /**
     * Render category archive (simulated)
     */
    private function renderCategoryArchive(string $categorySlug): string
    {
        return "<!DOCTYPE html>
<html lang='en'>
<head>
    <title>Category: {$categorySlug}</title>
</head>
<body>
    <h1>Category: {$categorySlug}</h1>
    <div class='posts'>
        <!-- Posts would be listed here -->
    </div>
</body>
</html>";
    }
    
    /**
     * Render author archive (simulated)
     */
    private function renderAuthorArchive(int $authorId): string
    {
        return "<!DOCTYPE html>
<html lang='en'>
<head>
    <title>Author Archive</title>
</head>
<body>
    <h1>Posts by Author {$authorId}</h1>
    <div class='posts'>
        <!-- Author posts would be listed here -->
    </div>
</body>
</html>";
    }
    
    /**
     * Render custom page (simulated)
     */
    private function renderCustomPage(int $pageId, string $template): string
    {
        return "<!DOCTYPE html>
<html lang='en'>
<head>
    <title>Custom Page {$pageId}</title>
</head>
<body>
    <div class='template-{$template}'>
        <h1>Custom Page</h1>
        <p>Using template: {$template}</p>
    </div>
</body>
</html>";
    }
    
    /**
     * Queue a page for background regeneration
     */
    public function queueRegeneration(string $callbackName, array $params = []): void
    {
        // In real implementation, this would dispatch to a background job queue
        echo "Queuing regeneration: {$callbackName}\n";
        
        $jobData = [
            'callback_name' => $callbackName,
            'params' => $params,
            'queued_at' => time()
        ];
        
        // Simulate background execution
        $this->executeBackgroundJob($jobData);
    }
    
    /**
     * Execute background job (simulated worker)
     */
    private function executeBackgroundJob(array $jobData): void
    {
        echo "  → Executing in background worker\n";
        
        $callbackName = $jobData['callback_name'];
        $params = $jobData['params'] ?? [];
        
        $callback = $this->registry->get($callbackName);
        
        if ($callback === null) {
            echo "  ✗ Error: Callback not found: {$callbackName}\n";
            return;
        }
        
        $html = $callback($params);
        
        // Cache the result
        $cacheKey = $this->generateCacheKey($callbackName, $params);
        $this->cacheHtml($cacheKey, $html);
        
        echo "  ✓ Cached content ({$cacheKey}): " . strlen($html) . " bytes\n";
    }
    
    /**
     * Generate cache key
     */
    private function generateCacheKey(string $callbackName, array $params): string
    {
        $paramsHash = md5(serialize($params));
        return "{$callbackName}_{$paramsHash}";
    }
    
    /**
     * Cache HTML content
     */
    private function cacheHtml(string $key, string $html): void
    {
        // In real implementation, save to file system or cache service
        @mkdir($this->cacheDir, 0755, true);
        file_put_contents("{$this->cacheDir}/{$key}.html", $html);
    }
    
    /**
     * Get callback registry (for inspection)
     */
    public function getRegistry(): CallbackRegistry
    {
        return $this->registry;
    }
}

// ============================================================================
// WordPress Hook Integration Example
// ============================================================================

// In your plugin or theme's functions.php:
/*
add_action('init', function() {
    global $wp_isr;
    $wp_isr = new WordPress_ISR_Plugin();
});

// Hook into post save to queue regeneration
add_action('save_post', function($postId) {
    global $wp_isr;
    
    // Queue single post regeneration
    $wp_isr->queueRegeneration('single_post', [
        'post_id' => $postId
    ]);
    
    // Also queue homepage if it shows recent posts
    $wp_isr->queueRegeneration('homepage');
});

// Hook into category update
add_action('edited_category', function($termId) {
    global $wp_isr;
    
    $category = get_term($termId);
    $wp_isr->queueRegeneration('category_archive', [
        'category_slug' => $category->slug
    ]);
});
*/

// ============================================================================
// DEMO
// ============================================================================

echo "=== WordPress ISR Integration Demo ===\n\n";

$plugin = new WordPress_ISR_Plugin();

echo "Registered Callbacks:\n";
foreach ($plugin->getRegistry()->list() as $name) {
    $metadata = $plugin->getRegistry()->getMetadata($name);
    echo "  • {$name}: " . $metadata['description'] . "\n";
}
echo "\n";

// Simulate various regeneration scenarios
echo "Scenario 1: Post Published\n";
echo "---------------------------\n";
$plugin->queueRegeneration('homepage');
$plugin->queueRegeneration('single_post', ['post_id' => 42]);
echo "\n";

echo "Scenario 2: Category Updated\n";
echo "---------------------------\n";
$plugin->queueRegeneration('category_archive', ['category_slug' => 'technology']);
echo "\n";

echo "Scenario 3: Author Profile Updated\n";
echo "---------------------------\n";
$plugin->queueRegeneration('author_archive', ['author_id' => 7]);
echo "\n";

echo "Scenario 4: Custom Page Modified\n";
echo "---------------------------\n";
$plugin->queueRegeneration('custom_page', [
    'page_id' => 123,
    'template' => 'landing-page'
]);
echo "\n";

// Show cached files
echo "Cached Files:\n";
$cacheDir = '/tmp/wp-isr-cache';
if (is_dir($cacheDir)) {
    $files = scandir($cacheDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $size = filesize("{$cacheDir}/{$file}");
            echo "  • {$file} ({$size} bytes)\n";
        }
    }
}
