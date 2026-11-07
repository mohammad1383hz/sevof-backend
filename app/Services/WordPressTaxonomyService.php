<?php

namespace App\Services;

use App\Models\Site;
use App\Models\WordpressArticle;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WordPressTaxonomyService
{
    /**
     * تعداد آیتم در هر صفحه هنگام واکشی categories/tags از وردپرس (حداکثر ۱۰۰ طبق مستندات WP REST API)
     */
    protected int $perPage = 100;

    /**
     * حداکثر تعداد صفحاتی که برای categories/tags (در حالت غیر-pool / fallback) واکشی می‌کنیم
     * سقف: perPage * maxPages = 100 * 3 = حداکثر ۳۰۰ آیتم برای هر taxonomy
     */
    protected int $maxPages = 3;

    /**
     * حداکثر تعداد پست (جدیدترین‌ها) که هنگام sync واکشی می‌شه.
     * فقط ۷۰ پست آخر، در یک ریکوئست (بدون pagination)
     */
    protected int $postsLimit = 50;

    /**
     * تایم‌اوت هر ریکوئست (ثانیه)
     */
    protected int $timeout = 10;

    /**
     * استخراج همزمان categories و tags از یک آدرس وردپرسی (بدون pool، سریال).
     * برای استفاده‌ی مستقل خارج از syncSiteWordpressData.
     *
     * @return array{categories: array<int, array{id:int,name:string,slug:string,count:int}>, tags: array<int, array{id:int,name:string,slug:string,count:int}>}
     */
    public function extract(string $siteUrl): array
    {
        return [
            'categories' => $this->fetchTaxonomy($siteUrl, 'categories'),
            'tags'       => $this->fetchTaxonomy($siteUrl, 'tags'),
        ];
    }

    public function extractCategories(string $siteUrl): array
    {
        return $this->fetchTaxonomy($siteUrl, 'categories');
    }

    public function extractTags(string $siteUrl): array
    {
        return $this->fetchTaxonomy($siteUrl, 'tags');
    }

    /**
     * واکشی یک taxonomy مشخص (categories یا tags) از REST API وردپرس با صفحه‌بندی (سریال).
     */
    protected function fetchTaxonomy(string $siteUrl, string $taxonomy): array
    {
        $base = $this->normalizeUrl($siteUrl);

        if ($base === null) {
            return [];
        }

        $endpoint = "{$base}/wp-json/wp/v2/{$taxonomy}";
        $results  = [];
        $page     = 1;

        try {
            do {
                $response = Http::timeout($this->timeout)
                    ->acceptJson()
                    ->get($endpoint, [
                        'per_page' => $this->perPage,
                        'page'     => $page,
                        '_fields'  => 'id,name,slug,count',
                    ]);

                if (! $response->successful()) {
                    break;
                }

                $data = $response->json();

                if (! is_array($data) || empty($data)) {
                    break;
                }

                foreach ($data as $item) {
                    if (! isset($item['id'], $item['name'], $item['slug'])) {
                        continue;
                    }

                    $results[] = [
                        'id'    => (int) $item['id'],
                        'name'  => (string) $item['name'],
                        'slug'  => (string) $item['slug'],
                        'count' => (int) ($item['count'] ?? 0),
                    ];
                }

                $hasMore = count($data) >= $this->perPage;
                $page++;
            } while ($hasMore && $page <= $this->maxPages);
        } catch (Throwable $e) {
            Log::warning("WordPressTaxonomyService: failed to fetch {$taxonomy} from {$endpoint}", [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return $results;
    }

    /**
     * استخراج ۷۰ مقاله‌ی آخر (جدیدترین) وردپرس به همراه category/tag امبد شده (سریال، تک ریکوئست).
     *
     * @return array<int, array{
     *   wp_post_id:int, title:string, url:string, excerpt:string,
     *   categories:array, tags:array, published_at:?string
     * }>
     */
    public function extractPosts(string $siteUrl): array
    {
        $base = $this->normalizeUrl($siteUrl);

        if ($base === null) {
            return [];
        }

        $endpoint = "{$base}/wp-json/wp/v2/posts";

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->get($endpoint, [
                    'per_page' => $this->postsLimit,
                    'page'     => 1,
                    '_embed'   => 'wp:term',
                    'orderby'  => 'date',
                    'order'    => 'desc',
                ]);

            return $this->parsePostsResponse($response);
        } catch (Throwable $e) {
            Log::warning("WordPressTaxonomyService: failed to fetch posts from {$endpoint}", [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * متد جامع و *بهینه‌شده*: categories، tags و posts رو هم‌زمان (با Http::pool، هر
     * سه‌تا با هم) از سایت می‌گیره، سپس روی Site و site_wordpress_articles ذخیره می‌کنه.
     * این جایگزین نسخه‌ی سریالِ قبلی هست و برای هر سایت فقط به‌اندازه‌ی کندترین
     * ریکوئست از بین سه‌تا زمان می‌بره، نه مجموع هر سه.
     *
     * @return array{categories_count:int, tags_count:int, posts_count:int}
     */
    public function syncSiteWordpressData(Site $site): array
    {
        $base = $this->normalizeUrl($site->url);

        if ($base === null) {
            return ['categories_count' => 0, 'tags_count' => 0, 'posts_count' => 0];
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => [
                $pool->as('categories')
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->get("{$base}/wp-json/wp/v2/categories", [
                        'per_page' => $this->perPage,
                        'page'     => 1,
                        '_fields'  => 'id,name,slug,count',
                    ]),
                $pool->as('tags')
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->get("{$base}/wp-json/wp/v2/tags", [
                        'per_page' => $this->perPage,
                        'page'     => 1,
                        '_fields'  => 'id,name,slug,count',
                    ]),
                $pool->as('posts')
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->get("{$base}/wp-json/wp/v2/posts", [
                        'per_page' => $this->postsLimit,
                        'page'     => 1,
                        '_embed'   => 'wp:term',
                        'orderby'  => 'date',
                        'order'    => 'desc',
                    ]),
            ]);
        } catch (Throwable $e) {
            Log::warning("WordPressTaxonomyService: pool request failed for site #{$site->id} ({$base})", [
                'message' => $e->getMessage(),
            ]);

            return ['categories_count' => 0, 'tags_count' => 0, 'posts_count' => 0];
        }

        $categories = $this->parseTaxonomyResponse($responses['categories'] ?? null);
        $tags       = $this->parseTaxonomyResponse($responses['tags'] ?? null);
        $posts      = $this->parsePostsResponse($responses['posts'] ?? null);

        $site->update([
            'categories' => $categories,
            'tags'       => $tags,
        ]);

        $postsCount = $this->savePosts($site, $posts);

        return [
            'categories_count' => count($categories),
            'tags_count'       => count($tags),
            'posts_count'      => $postsCount,
        ];
    }

    /**
     * ۷۰ مقاله‌ی آخر وردپرس سایت رو واکشی و در جدول site_wordpress_articles ذخیره/به‌روزرسانی می‌کنه.
     * (نسخه‌ی سریال مستقل، برای فراخوانی جدا از syncSiteWordpressData)
     *
     * @return int تعداد مقالات sync شده
     */
    public function syncPostsForSite(Site $site): int
    {
        $posts = $this->extractPosts($site->url);

        return $this->savePosts($site, $posts);
    }

    /**
     * پارس کردن پاسخ HTTP یک endpoint از نوع categories/tags به آرایه‌ی نرمال‌شده.
     * $response می‌تونه null یا instanceof Illuminate\Http\Client\Response باشه
     * (خروجی Http::pool هر آیتمش Response یا ConnectionException برمی‌گردونه).
     */
    protected function parseTaxonomyResponse($response): array
    {
        if (! $response instanceof \Illuminate\Http\Client\Response) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        $results = [];

        foreach ($data as $item) {
            if (! isset($item['id'], $item['name'], $item['slug'])) {
                continue;
            }

            $results[] = [
                'id'    => (int) $item['id'],
                'name'  => (string) $item['name'],
                'slug'  => (string) $item['slug'],
                'count' => (int) ($item['count'] ?? 0),
            ];
        }

        return $results;
    }

    /**
     * پارس کردن پاسخ HTTP endpoint پست‌ها به آرایه‌ی نرمال‌شده.
     */
    protected function parsePostsResponse($response): array
    {
        if (! $response instanceof \Illuminate\Http\Client\Response) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        $results = [];

        foreach ($data as $post) {
            if (! isset($post['id'], $post['link'])) {
                continue;
            }

            [$categories, $tags] = $this->extractEmbeddedTerms($post);

            $results[] = [
                'wp_post_id'   => (int) $post['id'],
                'title'        => (string) ($post['title']['rendered'] ?? ''),
                'url'          => (string) $post['link'],
                'excerpt'      => $this->cleanExcerpt($post['excerpt']['rendered'] ?? ''),
                'categories'   => $categories,
                'tags'         => $tags,
                'published_at' => $post['date'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * ذخیره‌ی آرایه‌ی پست‌های پارس‌شده در جدول site_wordpress_articles (idempotent).
     */
    protected function savePosts(Site $site, array $posts): int
    {
        if (empty($posts)) {
            return 0;
        }

        $now = now();

        DB::transaction(function () use ($posts, $site, $now) {
            foreach ($posts as $post) {
                WordpressArticle::updateOrCreate(
                    [
                        'site_id'    => $site->id,
                        'wp_post_id' => $post['wp_post_id'],
                    ],
                    [
                        'title'        => Str::limit($post['title'], 250, ''),
                        'url'          => $post['url'],
                        'excerpt'      => $post['excerpt'],
                        'categories'   => $post['categories'],
                        'tags'         => $post['tags'],
                        'published_at' => $post['published_at'],
                        'synced_at'    => $now,
                    ]
                );
            }
        });

        return count($posts);
    }

    /**
     * از _embedded['wp:term'] که وردپرس برمی‌گردونه، categories و tags رو جدا می‌کنه.
     */
    protected function extractEmbeddedTerms(array $post): array
    {
        $categories = [];
        $tags       = [];

        $termGroups = $post['_embedded']['wp:term'] ?? [];

        foreach ($termGroups as $group) {
            foreach ($group as $term) {
                if (! isset($term['id'], $term['name'], $term['slug'], $term['taxonomy'])) {
                    continue;
                }

                $item = [
                    'id'   => (int) $term['id'],
                    'name' => (string) $term['name'],
                    'slug' => (string) $term['slug'],
                ];

                if ($term['taxonomy'] === 'category') {
                    $categories[] = $item;
                } elseif ($term['taxonomy'] === 'post_tag') {
                    $tags[] = $item;
                }
            }
        }

        return [$categories, $tags];
    }

    protected function cleanExcerpt(string $html): string
    {
        return trim(strip_tags($html));
    }

    /**
     * نرمال‌سازی آدرس سایت: حذف اسلش انتهایی، اطمینان از داشتن اسکیم.
     */
    protected function normalizeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }
}
