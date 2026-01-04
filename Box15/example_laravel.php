<?php

/**
 * Laravel ISR (Incremental Static Regeneration) Integration
 * 
 * This example shows how to integrate CallbackRegistry with Laravel
 * for ISR-style background cache regeneration.
 */

require_once __DIR__ . '/CallbackRegistry.php';

// ============================================================================
// Laravel Service Provider
// ============================================================================

/**
 * ISR Service Provider
 * Register this in config/app.php providers array
 */
class ISRServiceProvider
{
    private CallbackRegistry $registry;
    
    public function __construct()
    {
        $this->registry = new CallbackRegistry();
    }
    
    /**
     * Register ISR callbacks
     * In Laravel, this would be called in the boot() method
     */
    public function register(): void
    {
        // Homepage
        $this->registry->register('homepage', function() {
            return $this->renderView('pages.home');
        }, [
            'description' => 'Homepage',
            'ttl' => 3600,
            'tags' => ['static', 'high-traffic']
        ]);
        
        // Blog post
        $this->registry->register('blog.show', function($params) {
            return $this->renderView('blog.show', [
                'post' => $this->getPost($params['slug'])
            ]);
        }, [
            'description' => 'Individual blog post',
            'ttl' => 7200,
            'tags' => ['blog', 'content']
        ]);
        
        // Blog index
        $this->registry->register('blog.index', function($params) {
            $page = $params['page'] ?? 1;
            return $this->renderView('blog.index', [
                'posts' => $this->getPaginatedPosts($page)
            ]);
        }, [
            'description' => 'Blog listing page',
            'ttl' => 1800,
            'tags' => ['blog', 'listing']
        ]);
        
        // Product page
        $this->registry->register('products.show', function($params) {
            return $this->renderView('products.show', [
                'product' => $this->getProduct($params['id'])
            ]);
        }, [
            'description' => 'Product detail page',
            'ttl' => 3600,
            'tags' => ['ecommerce', 'product']
        ]);
        
        // Category page
        $this->registry->register('categories.show', function($params) {
            return $this->renderView('categories.show', [
                'category' => $this->getCategory($params['slug']),
                'products' => $this->getCategoryProducts($params['slug'])
            ]);
        }, [
            'description' => 'Category page with products',
            'ttl' => 1800,
            'tags' => ['ecommerce', 'category']
        ]);
        
        // API endpoints
        $this->registry->register('api.products.index', function($params) {
            return json_encode([
                'data' => $this->getProducts($params),
                'meta' => [
                    'generated_at' => time()
                ]
            ]);
        }, [
            'description' => 'Products API endpoint',
            'ttl' => 600,
            'tags' => ['api']
        ]);
    }
    
    /**
     * Simulate rendering a Laravel view
     */
    private function renderView(string $view, array $data = []): string
    {
        // In real Laravel: return view($view, $data)->render();
        
        $viewName = str_replace('.', '/', $view);
        $dataStr = !empty($data) ? json_encode($data) : 'no data';
        
        return "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>{$viewName}</title>
</head>
<body>
    <div class='view-{$viewName}'>
        <h1>{$viewName}</h1>
        <pre>" . htmlspecialchars($dataStr) . "</pre>
    </div>
</body>
</html>";
    }
    
    /**
     * Simulate fetching a post
     */
    private function getPost(string $slug): array
    {
        // In real Laravel: return Post::where('slug', $slug)->firstOrFail();
        return [
            'slug' => $slug,
            'title' => 'Sample Post: ' . ucwords(str_replace('-', ' ', $slug)),
            'content' => 'Lorem ipsum dolor sit amet...',
            'published_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Simulate fetching paginated posts
     */
    private function getPaginatedPosts(int $page): array
    {
        // In real Laravel: return Post::paginate(15);
        return [
            'current_page' => $page,
            'data' => [
                ['id' => 1, 'title' => 'Post 1'],
                ['id' => 2, 'title' => 'Post 2'],
            ],
            'total' => 50
        ];
    }
    
    /**
     * Simulate fetching a product
     */
    private function getProduct(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Product ' . $id,
            'price' => 99.99,
            'in_stock' => true
        ];
    }
    
    /**
     * Simulate fetching a category
     */
    private function getCategory(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'description' => 'Category description'
        ];
    }
    
    /**
     * Simulate fetching category products
     */
    private function getCategoryProducts(string $slug): array
    {
        return [
            ['id' => 1, 'name' => 'Product 1'],
            ['id' => 2, 'name' => 'Product 2'],
        ];
    }
    
    /**
     * Simulate fetching products with filters
     */
    private function getProducts(array $params): array
    {
        return [
            ['id' => 1, 'name' => 'Product 1', 'price' => 99.99],
            ['id' => 2, 'name' => 'Product 2', 'price' => 149.99],
        ];
    }
    
    /**
     * Get the registry instance
     */
    public function getRegistry(): CallbackRegistry
    {
        return $this->registry;
    }
}

// ============================================================================
// Laravel Job Example
// ============================================================================

/**
 * Regenerate Static Content Job
 * 
 * Usage: dispatch(new RegenerateStaticContent('blog.show', ['slug' => 'my-post']));
 */
class RegenerateStaticContent
{
    private string $callbackName;
    private array $params;
    private CallbackRegistry $registry;
    
    public function __construct(string $callbackName, array $params = [])
    {
        $this->callbackName = $callbackName;
        $this->params = $params;
    }
    
    /**
     * Execute the job
     */
    public function handle(CallbackRegistry $registry): void
    {
        echo "Job started: Regenerating {$this->callbackName}\n";
        
        $callback = $registry->get($this->callbackName);
        
        if ($callback === null) {
            throw new \Exception("Callback not found: {$this->callbackName}");
        }
        
        // Generate content
        $startTime = microtime(true);
        $content = $callback($this->params);
        $duration = microtime(true) - $startTime;
        
        // Cache the content
        $cacheKey = $this->generateCacheKey();
        $this->cache($cacheKey, $content);
        
        echo "✓ Regenerated in " . round($duration * 1000, 2) . "ms\n";
        echo "✓ Cached {$cacheKey}: " . strlen($content) . " bytes\n";
    }
    
    private function generateCacheKey(): string
    {
        $paramsHash = md5(json_encode($this->params));
        return "isr:{$this->callbackName}:{$paramsHash}";
    }
    
    private function cache(string $key, string $content): void
    {
        // In Laravel: Cache::put($key, $content, $ttl);
        $cacheDir = '/tmp/laravel-isr-cache';
        @mkdir($cacheDir, 0755, true);
        file_put_contents("{$cacheDir}/" . str_replace(':', '_', $key) . ".html", $content);
    }
}

// ============================================================================
// Laravel Model Observers Example
// ============================================================================

/**
 * Post Observer
 * Automatically queue regeneration when posts are updated
 */
class PostObserver
{
    /**
     * Handle the Post "saved" event
     */
    public function saved($post): void
    {
        echo "Post saved: {$post['slug']}\n";
        
        // Queue regeneration jobs
        $this->dispatchJob('blog.show', ['slug' => $post['slug']]);
        $this->dispatchJob('blog.index', ['page' => 1]);
        $this->dispatchJob('homepage');
    }
    
    /**
     * Handle the Post "deleted" event
     */
    public function deleted($post): void
    {
        echo "Post deleted: {$post['slug']}\n";
        
        // Queue regeneration of index and homepage
        $this->dispatchJob('blog.index', ['page' => 1]);
        $this->dispatchJob('homepage');
    }
    
    private function dispatchJob(string $callbackName, array $params = []): void
    {
        // In Laravel: dispatch(new RegenerateStaticContent($callbackName, $params));
        echo "  → Queued: {$callbackName}\n";
    }
}

// ============================================================================
// DEMO
// ============================================================================

echo "=== Laravel ISR Integration Demo ===\n\n";

// Initialize service provider
$provider = new ISRServiceProvider();
$provider->register();
$registry = $provider->getRegistry();

echo "Registered Callbacks:\n";
foreach ($registry->list() as $name) {
    $metadata = $registry->getMetadata($name);
    echo "  • {$name}";
    echo " (TTL: " . ($metadata['ttl'] ?? 'N/A') . "s)";
    echo " [" . implode(', ', $metadata['tags'] ?? []) . "]";
    echo "\n";
}
echo "\n";

// Scenario 1: Blog post published
echo "Scenario 1: Blog Post Published\n";
echo "--------------------------------\n";
$post = ['slug' => 'my-awesome-post', 'title' => 'My Awesome Post'];
$observer = new PostObserver();
$observer->saved($post);

// Execute queued jobs
$job1 = new RegenerateStaticContent('blog.show', ['slug' => 'my-awesome-post']);
$job1->handle($registry);

$job2 = new RegenerateStaticContent('blog.index', ['page' => 1]);
$job2->handle($registry);
echo "\n";

// Scenario 2: Product updated
echo "Scenario 2: Product Updated\n";
echo "----------------------------\n";
$job3 = new RegenerateStaticContent('products.show', ['id' => 42]);
$job3->handle($registry);
echo "\n";

// Scenario 3: Category page
echo "Scenario 3: Category Page Regeneration\n";
echo "---------------------------------------\n";
$job4 = new RegenerateStaticContent('categories.show', ['slug' => 'electronics']);
$job4->handle($registry);
echo "\n";

// Scenario 4: API endpoint
echo "Scenario 4: API Endpoint Regeneration\n";
echo "--------------------------------------\n";
$job5 = new RegenerateStaticContent('api.products.index', ['category' => 'featured']);
$job5->handle($registry);
echo "\n";

// Show cached files
echo "Cached Files:\n";
$cacheDir = '/tmp/laravel-isr-cache';
if (is_dir($cacheDir)) {
    $files = scandir($cacheDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $size = filesize("{$cacheDir}/{$file}");
            echo "  • {$file} ({$size} bytes)\n";
        }
    }
}
