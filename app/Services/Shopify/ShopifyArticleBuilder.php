<?php

namespace App\Services\Shopify;

use App\Models\Article;
use App\Models\Banner;
use App\Services\Image\ImageOptimizer;
use App\Support\ContentLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\CommonMarkConverter;

/**
 * Article → Shopify ArticleCreateInput/ArticleUpdateInput.
 * Images (cover + gallery) are uploaded to Shopify Files so the store never hotlinks our server.
 */
class ShopifyArticleBuilder
{
    private const IMAGE_DISK = 'public';

    private CommonMarkConverter $markdown;

    public function __construct()
    {
        $this->markdown = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function build(Article $article, ShopifyClient $client, bool $published, bool $forCreate): array
    {
        $input = [
            'title' => $article->title,
            'body' => $this->buildBody($article, $client),
            'handle' => $article->slug ?: null,
            'summary' => $article->meta_description ? '<p>'.e($article->meta_description).'</p>' : null,
            'isPublished' => $published,
            'tags' => self::tagNames($article),
            'author' => ['name' => $article->site->name ?: config('app.name', 'Sevof')],
        ];

        if ($forCreate) {
            $input['blogId'] = $this->blogId($client);
        }

        if ($image = $this->cover($article, $client)) {
            $input['image'] = $image;
        }

        // Shopify uses the title as <title> unless global.title_tag is set.
        if ($article->meta_title && $article->meta_title !== $article->title) {
            $input['metafields'] = [[
                'namespace' => 'global',
                'key' => 'title_tag',
                'type' => 'single_line_text_field',
                'value' => $article->meta_title,
            ]];
        }

        return array_filter($input, fn ($v) => $v !== null && $v !== '') + ['isPublished' => $published];
    }

    public function blogId(ShopifyClient $client): string
    {
        $connection = $client->connection();

        if (! $connection->blog_id) {
            $connection->update(['blog_id' => $client->defaultBlogId()]);
        }

        return (string) $connection->blog_id;
    }

    // ---------- Body ----------

    private function buildBody(Article $article, ShopifyClient $client): string
    {
        $sections = $article->sections;
        $gallery = $this->placeGallery($sections, $article->image_gallery, $client);

        $html = $this->bannerHtml($article) ?? '';
        $html .= implode('', $gallery['lead']);

        foreach ($sections as $section) {
            $html .= '<h2>'.$this->inline($section->heading).'</h2>';
            $html .= $this->block($section->content);
            $html .= implode('', $gallery['sections'][$section->position] ?? []);
        }

        return ContentLanguage::isRtl(ContentLanguage::forSite($article->site))
            ? '<div dir="rtl" style="direction:rtl;text-align:right">'.$html.'</div>'
            : $html;
    }

    /** Gallery images grouped by after_section (same placement as the WordPress publisher). */
    private function placeGallery(Collection $sections, mixed $gallery, ShopifyClient $client): array
    {
        $gallery = $this->normalizeGallery($gallery);
        $positions = $sections->pluck('position')->values()->all();

        usort($gallery, fn ($a, $b) => [($a['after_section'] ?? 0), ($a['order'] ?? 0)] <=> [($b['after_section'] ?? 0), ($b['order'] ?? 0)]);

        $lead = [];
        $bySection = [];

        foreach ($gallery as $item) {
            if ((isset($item['status']) && $item['status'] !== 'generated') || ! empty($item['error'])) {
                continue;
            }

            $file = self::readStoredFile($item['path'] ?? null);

            if ($file === null) {
                continue;
            }

            $alt = (string) ($item['alt'] ?? '');
            $url = $client->uploadImage($file['contents'], $file['filename'], $alt);
            $figure = '<figure style="text-align:center"><img src="'.e($url).'" alt="'.e($alt).'" style="max-width:100%;height:auto"></figure>';

            $after = $item['after_section'] ?? 0;
            $target = null;

            foreach ($positions as $position) {
                if ($position > $after) {
                    break;
                }
                $target = $position;
            }

            if ($target === null) {
                $lead[] = $figure;
            } else {
                $bySection[$target][] = $figure;
            }
        }

        return ['lead' => $lead, 'sections' => $bySection];
    }

    private function normalizeGallery(mixed $gallery): array
    {
        if ($gallery instanceof Collection) {
            return $gallery->all();
        }

        if (is_string($gallery)) {
            $gallery = json_decode($gallery, true);
        }

        return is_array($gallery) ? $gallery : [];
    }

    private function bannerHtml(Article $article): ?string
    {
        $banner = $article->latestBanner();

        return ($banner && $banner->status === Banner::STATUS_GENERATED && $banner->html) ? $banner->html : null;
    }

    private function cover(Article $article, ShopifyClient $client): ?array
    {
        if ($article->image_status !== 'generated') {
            return null;
        }

        $file = self::readStoredFile($article->image_path);

        if ($file === null) {
            return null;
        }

        $alt = (string) ($article->image_alt ?? $article->title);

        return ['url' => $client->uploadImage($file['contents'], $file['filename'], $alt), 'altText' => $alt];
    }

    /**
     * Reads an image from the public disk (absolute or relative path), re-encoding large/non-WebP files.
     *
     * @return array{contents: string, filename: string}|null
     */
    public static function readStoredFile(?string $path): ?array
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk(self::IMAGE_DISK);
        $root = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $disk->path(''));
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);

        if (str_starts_with($normalized, $root)) {
            $path = str_replace('\\', '/', ltrim(substr($normalized, strlen($root)), '\\/'));
        }

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);
        $filename = basename($path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext !== 'webp' || strlen($contents) > ImageOptimizer::TARGET_BYTES) {
            [$contents, $newExt] = app(ImageOptimizer::class)->optimize($contents, $ext ?: 'jpg');
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.'.$newExt;
        }

        return ['contents' => $contents, 'filename' => $filename];
    }

    // ---------- Text helpers ----------

    private function block(?string $text): string
    {
        return $text ? trim((string) $this->markdown->convert($text)->getContent()) : '';
    }

    private function inline(?string $text): string
    {
        if (! $text) {
            return '';
        }

        $html = trim((string) $this->markdown->convert($text)->getContent());

        return trim(preg_replace('/^<p>(.*)<\/p>$/s', '$1', $html) ?? $html);
    }

    public static function tagNames(Article $article): array
    {
        $siteTags = collect($article->site->tags ?? []);

        $existing = collect($article->wp_tag_ids ?? [])
            ->map(fn ($id) => $siteTags->firstWhere('id', $id)['name'] ?? null)
            ->filter();

        $new = collect($article->wp_new_tags ?? [])
            ->map(fn ($name) => trim((string) $name))
            ->filter();

        return $existing->merge($new)->unique()->values()->all();
    }
}
