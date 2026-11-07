<?php

namespace App\Services\Shopify;

use App\Models\ShopifyConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Shopify GraphQL Admin API for one connected store.
 * Every mutation's userErrors become a ShopifyApiException (422), a missing node a 404.
 */
class ShopifyClient
{
    private const ARTICLE_FIELDS = 'id handle title isPublished publishedAt blog { id handle }';

    public function __construct(private readonly ShopifyConnection $connection)
    {
    }

    public function connectionId(): int
    {
        return $this->connection->id;
    }

    public function connection(): ShopifyConnection
    {
        return $this->connection;
    }

    // ---------- Shop / blogs ----------

    /** @return array{name: string, primaryDomain: array{url: string}} */
    public function shop(): array
    {
        return (array) $this->query('{ shop { name primaryDomain { url } } }')['shop'];
    }

    /** First blog of the store, created if none exists. Returns its GID. */
    public function defaultBlogId(): string
    {
        $blogs = (array) data_get($this->query('{ blogs(first: 1) { nodes { id } } }'), 'blogs.nodes', []);

        if (! empty($blogs[0]['id'])) {
            return $blogs[0]['id'];
        }

        $data = $this->mutate('blogCreate', 'mutation($blog: BlogCreateInput!) {
            blogCreate(blog: $blog) { blog { id } userErrors { field message } }
        }', ['blog' => ['title' => config('shopify.default_blog_title', 'News')]]);

        return (string) data_get($data, 'blog.id');
    }

    // ---------- Articles ----------

    public function createArticle(array $input): array
    {
        $data = $this->mutate('articleCreate', 'mutation($article: ArticleCreateInput!) {
            articleCreate(article: $article) { article { '.self::ARTICLE_FIELDS.' } userErrors { field message } }
        }', ['article' => $input]);

        return (array) $data['article'];
    }

    public function updateArticle(string $id, array $input): array
    {
        $data = $this->mutate('articleUpdate', 'mutation($id: ID!, $article: ArticleUpdateInput!) {
            articleUpdate(id: $id, article: $article) { article { '.self::ARTICLE_FIELDS.' } userErrors { field message } }
        }', ['id' => $id, 'article' => $input]);

        if (empty($data['article'])) {
            throw new ShopifyApiException('Article not found in Shopify.', 404);
        }

        return (array) $data['article'];
    }

    public function getArticle(string $id): array
    {
        $article = $this->query('query($id: ID!) { article(id: $id) { '.self::ARTICLE_FIELDS.' body summary tags image { url altText } } }', ['id' => $id])['article'] ?? null;

        if (! $article) {
            throw new ShopifyApiException('Article not found in Shopify.', 404);
        }

        return (array) $article;
    }

    public function deleteArticle(string $id): void
    {
        $this->mutate('articleDelete', 'mutation($id: ID!) {
            articleDelete(id: $id) { deletedArticleId userErrors { field message } }
        }', ['id' => $id]);
    }

    /**
     * Blog articles of the store, newest first (used for "update past articles").
     *
     * @return array{nodes: list<array>, pageInfo: array{hasNextPage: bool, endCursor: ?string}}
     */
    public function listArticles(int $first = 50, ?string $after = null): array
    {
        return (array) $this->query('query($first: Int!, $after: String) {
            articles(first: $first, after: $after, sortKey: PUBLISHED_AT, reverse: true) {
                nodes { '.self::ARTICLE_FIELDS.' body summary tags updatedAt image { url altText } }
                pageInfo { hasNextPage endCursor }
            }
        }', ['first' => $first, 'after' => $after])['articles'];
    }

    /** Public URL of an article on the storefront. */
    public function articleUrl(array $article): ?string
    {
        $blog = data_get($article, 'blog.handle');
        $handle = $article['handle'] ?? null;

        return $blog && $handle ? $this->connection->storefrontUrl()."/blogs/{$blog}/{$handle}" : null;
    }

    // ---------- Products ----------

    public function products(int $first, ?string $after = null): array
    {
        return (array) $this->query('query($first: Int!, $after: String) {
            products(first: $first, after: $after, sortKey: UPDATED_AT, reverse: true, query: "status:active") {
                nodes {
                    id legacyResourceId title handle descriptionHtml onlineStoreUrl vendor productType tags
                    totalInventory tracksInventory updatedAt
                    seo { title description }
                    priceRangeV2 { minVariantPrice { amount currencyCode } }
                    compareAtPriceRange { maxVariantCompareAtPrice { amount } }
                    variants(first: 1) { nodes { sku } }
                    media(first: 10) { nodes { id alt ... on MediaImage { image { url } } } }
                    collections(first: 3) { nodes { id legacyResourceId title handle } }
                }
                pageInfo { hasNextPage endCursor }
            }
        }', ['first' => $first, 'after' => $after])['products'];
    }

    public function getProduct(string $id): array
    {
        $product = $this->query('query($id: ID!) {
            product(id: $id) {
                id title descriptionHtml tags seo { title description }
                media(first: 50) { nodes { id alt mediaContentType } }
            }
        }', ['id' => $id])['product'] ?? null;

        if (! $product) {
            throw new ShopifyApiException('Product not found in Shopify.', 404);
        }

        return (array) $product;
    }

    public function updateProduct(array $product): array
    {
        return (array) $this->mutate('productUpdate', 'mutation($product: ProductUpdateInput!) {
            productUpdate(product: $product) { product { id } userErrors { field message } }
        }', ['product' => $product])['product'];
    }

    /** Adds tags without touching existing ones. */
    public function addTags(string $id, array $tags): void
    {
        $this->mutate('tagsAdd', 'mutation($id: ID!, $tags: [String!]!) {
            tagsAdd(id: $id, tags: $tags) { node { id } userErrors { field message } }
        }', ['id' => $id, 'tags' => array_values($tags)]);
    }

    /** @param list<array{id: string, alt: string}> $files */
    public function updateFileAlts(array $files): void
    {
        if ($files === []) {
            return;
        }

        $this->mutate('fileUpdate', 'mutation($files: [FileUpdateInput!]!) {
            fileUpdate(files: $files) { files { id } userErrors { field message } }
        }', ['files' => $files]);
    }

    // ---------- Files ----------

    /**
     * Uploads image bytes to Shopify Files and returns the CDN URL.
     * Cached per store + content hash so republishing does not duplicate files.
     */
    public function uploadImage(string $contents, string $filename, string $alt = ''): string
    {
        $key = "shopify:{$this->connection->id}:img:".sha1($contents);

        $url = Cache::get($key);

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $url = $this->doUploadImage($contents, $filename, $alt);
        Cache::put($key, $url, now()->addDays((int) config('shopify.image_cache_days', 30)));

        return $url;
    }

    private function doUploadImage(string $contents, string $filename, string $alt): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'image/jpeg';

        $staged = $this->mutate('stagedUploadsCreate', 'mutation($input: [StagedUploadInput!]!) {
            stagedUploadsCreate(input: $input) {
                stagedTargets { url resourceUrl parameters { name value } }
                userErrors { field message }
            }
        }', ['input' => [[
            'resource' => 'IMAGE',
            'filename' => $filename,
            'mimeType' => $mime,
            'httpMethod' => 'POST',
            'fileSize' => (string) strlen($contents),
        ]]]);

        $target = $staged['stagedTargets'][0] ?? null;

        if (! $target) {
            throw new ShopifyApiException('Shopify did not return an upload target.');
        }

        $request = Http::asMultipart()->timeout((int) config('shopify.timeout', 60));

        foreach ((array) $target['parameters'] as $param) {
            $request = $request->attach($param['name'], $param['value']);
        }

        $upload = $request->attach('file', $contents, $filename)->post($target['url']);

        if (! $upload->successful()) {
            throw new ShopifyApiException("Image upload to Shopify failed (HTTP {$upload->status()}).", $upload->status());
        }

        $created = $this->mutate('fileCreate', 'mutation($files: [FileCreateInput!]!) {
            fileCreate(files: $files) { files { id fileStatus ... on MediaImage { image { url } } } userErrors { field message } }
        }', ['files' => [[
            'originalSource' => $target['resourceUrl'],
            'contentType' => 'IMAGE',
            'alt' => mb_substr($alt, 0, 512),
        ]]]);

        $file = $created['files'][0] ?? [];
        $url = data_get($file, 'image.url');

        // Shopify processes files asynchronously; poll briefly until the CDN URL exists.
        for ($i = 0; ! $url && $i < 15 && ! empty($file['id']); $i++) {
            usleep(1_000_000);

            $node = $this->query('query($id: ID!) { node(id: $id) { ... on MediaImage { fileStatus image { url } } } }', ['id' => $file['id']])['node'] ?? [];

            if (($node['fileStatus'] ?? null) === 'FAILED') {
                throw new ShopifyApiException('Shopify could not process the uploaded image.');
            }

            $url = data_get($node, 'image.url');
        }

        if (! $url) {
            throw new ShopifyApiException('Shopify did not finish processing the image in time.');
        }

        return (string) $url;
    }

    // ---------- Internals ----------

    /**
     * Runs a mutation and throws on userErrors. Returns the payload of $field.
     */
    public function mutate(string $field, string $query, array $variables = []): array
    {
        $payload = (array) ($this->query($query, $variables)[$field] ?? []);
        $errors = array_values(array_filter((array) ($payload['userErrors'] ?? [])));

        if ($errors !== []) {
            $message = implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'error'), $errors));
            $notFound = (bool) preg_match('/not (be )?found|does not exist/i', $message);

            throw new ShopifyApiException("Shopify: {$message}", $notFound ? 404 : 422, $errors);
        }

        return $payload;
    }

    public function query(string $query, array $variables = []): array
    {
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => (string) $this->connection->access_token])
                ->acceptJson()
                ->timeout((int) config('shopify.timeout', 60))
                ->retry(3, 1000, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->status() === 429), throw: false)
                ->post($this->endpoint(), ['query' => $query, 'variables' => (object) $variables]);
        } catch (ConnectionException $e) {
            throw new ShopifyApiException('Could not reach Shopify: '.$e->getMessage());
        }

        $json = $response->json();

        if (! $response->successful()) {
            $message = is_array($json) ? (string) ($json['errors'] ?? '') : '';
            $message = $message !== '' && ! is_array($json['errors'] ?? null) ? $message : "HTTP {$response->status()}";

            throw new ShopifyApiException("Shopify: {$message}", $response->status(), $json);
        }

        if (! empty($json['errors'])) {
            $first = (array) ($json['errors'][0] ?? []);
            $throttled = data_get($first, 'extensions.code') === 'THROTTLED';

            throw new ShopifyApiException('Shopify: '.($first['message'] ?? 'GraphQL error'), $throttled ? 429 : 400, $json['errors']);
        }

        return (array) ($json['data'] ?? []);
    }

    private function endpoint(): string
    {
        return 'https://'.$this->connection->shop_domain.'/admin/api/'.config('shopify.api_version').'/graphql.json';
    }
}
