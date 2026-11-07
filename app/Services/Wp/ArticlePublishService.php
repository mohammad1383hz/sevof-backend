<?php

namespace App\Services\Wp;

use App\Models\Article;
use App\Models\AgentSetting;
use App\Models\ArticlePublishLog;
use App\Models\Banner;
use App\Models\SiteConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\CommonMarkConverter;
use Throwable;

/**
 * تمام ارتباط خروجی Laravel -> پلاگین وردپرس برای publish/update/delete/sync
 * در همین سرویس متمرکز است. Job ها فقط این متدها را صدا می‌زنند.
 *
 * مدل جدید:
 * - wp_sync_status: وضعیت خودِ عملیات Sync با وردپرس
 *   (not_synced | syncing | synced | failed)
 * - publish_status: وضعیت Post در وردپرس (draft | published) — فقط زمانی
 *   معنا دارد که wp_sync_status == synced باشد.
 *
 * دسته‌بندی و تگ:
 * - wp_category_id روی article، همان id دسته‌ای است که AI از بین
 *   sites.categories انتخاب کرده و مستقیماً به‌صورت category_id به پلاگین
 *   فرستاده می‌شود (چون از قبل مطمئنیم این id در وردپرس وجود دارد).
 * - برای تگ‌ها اما به‌جای id، اسم تگ‌ها فرستاده می‌شود (هم تگ‌های موجود
 *   انتخاب‌شده و هم تگ‌های جدید پیشنهادی AI با هم، یک آرایه از رشته).
 *   دلیلش این است که سمت پلاگین از wp_set_post_tags() استفاده می‌کند که
 *   خودِ وردپرس تگ‌های جدید را می‌سازد و تگ‌های موجود را با تطبیق نام پیدا
 *   می‌کند؛ بنابراین نیازی به pre-create کردن تگ جدید از سمت لاراول نیست
 *   و ریسک ناهماهنگی id بین دو سیستم هم از بین می‌رود.
 */
class ArticlePublishService implements \App\Contracts\ArticlePublisher
{
    /**
     * هر وقت ساختار payload تغییر کنه (بنر، lead_images، images روی هر
     * section، و حالا category_id/tags) این عدد باید بره بالا تا پلاگین
     * بفهمه با کدوم نسخه از قرارداد طرفه.
     */
    protected const PAYLOAD_VERSION = 4;

    protected const IMAGE_DISK = 'public';
    // Publish copies stay below common PHP/proxy body limits; source files are never modified.
    // تصاویر جدید از قبل WebP و ~۱۰۰-۲۰۰KB ذخیره می‌شوند؛ این سقف برای تصاویر قدیمیِ سنگین است.
    protected const MAX_WORDPRESS_IMAGE_BYTES = 300_000;
    protected const MAX_WORDPRESS_IMAGE_DIMENSION = 1600;

    protected CommonMarkConverter $markdown;

    public function __construct(
        private readonly AgentSignatureService $signer,
    ) {
        $this->markdown = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function create(Article $article): array
    {
        try {
            $connection = $this->connectionOrFail($article);
        } catch (Throwable $e) {
            Log::warning('WordPress connection unavailable before publish', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->fail($article, ArticlePublishLog::ACTION_PUBLISH, $e->getMessage());
        }

        $postStatus = $this->resolvePostStatus($article);

        $article->update(['wp_sync_status' => 'syncing']);

        try {
            $payload = $this->buildPayload($article, $postStatus);
        } catch (Throwable $e) {
            Log::warning('WordPress article payload preparation failed', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->fail($article, ArticlePublishLog::ACTION_PUBLISH, $e->getMessage());
        }

        $result = $this->call(
            $connection,
            'POST',
            config('wordpress_agent.plugin_routes.articles'),
            $payload,
            config('wordpress_agent.signing_paths.articles')
        );

        $this->logResult($article, ArticlePublishLog::ACTION_PUBLISH, $result);

        if ($result['ok']) {
            $article->update([
                'wp_post_id' => is_array($result['body'] ?? null) ? ($result['body']['wp_post_id'] ?? null) : null,
                'wp_permalink' => is_array($result['body'] ?? null) ? ($result['body']['permalink'] ?? null) : null,
                'wp_sync_status' => 'synced',
                'publish_status' => $postStatus === 'publish' ? 'published' : 'draft',
                'publish_error' => null,
                'published_at' => $postStatus === 'publish' ? now() : null,
                'last_synced_at' => now(),
            ]);

            $this->syncTaxonomyBack($article, $result['body'] ?? []);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    public function update(Article $article): array
    {
        try {
            $connection = $this->connectionOrFail($article);
        } catch (Throwable $e) {
            Log::warning('WordPress connection unavailable before update', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->fail($article, ArticlePublishLog::ACTION_UPDATE, $e->getMessage());
        }

        if (! $article->wp_post_id) {
            return $this->fail($article, ArticlePublishLog::ACTION_UPDATE, 'The article hasn\'t been published to WordPress yet.');
        }

        $article->update(['wp_sync_status' => 'syncing']);

        $postStatus = $this->resolvePostStatus($article);
        try {
            $payload = $this->buildPayload($article, $postStatus);
        } catch (Throwable $e) {
            Log::warning('WordPress article update payload preparation failed', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->fail($article, ArticlePublishLog::ACTION_UPDATE, $e->getMessage());
        }

        $path = rtrim(config('wordpress_agent.plugin_routes.articles'), '/') . '/' . $article->wp_post_id;
        $signingPath = rtrim(config('wordpress_agent.signing_paths.articles'), '/') . '/' . $article->wp_post_id;

        $result = $this->call($connection, 'PUT', $path, $payload, $signingPath);

        // When a Laravel article is moved to another WordPress site, its old
        // wp_post_id belongs to the previous site. The new site correctly
        // returns 404 because that managed post does not exist there. Treat
        // only this not-found case as a re-create, rather than making the user
        // manually clear the stale remote id.
        if ($this->isManagedArticleNotFound($result)) {
            Log::notice('WordPress managed article not found during update; recreating on destination site', [
                'article_id'       => $article->id,
                'old_wp_post_id'   => $article->wp_post_id,
                'destination_site' => $article->website_id,
            ]);

            $article->update([
                'wp_post_id'   => null,
                'wp_permalink' => null,
                'wp_sync_status' => 'not_synced',
            ]);

            return $this->create($article);
        }

        $this->logResult($article, ArticlePublishLog::ACTION_UPDATE, $result);

        if ($result['ok']) {
            $article->update([
                'wp_sync_status' => 'synced',
                'publish_status' => $postStatus === 'publish' ? 'published' : 'draft',
                'wp_permalink' => is_array($result['body'] ?? null) ? ($result['body']['permalink'] ?? $article->wp_permalink) : $article->wp_permalink,
                'publish_error' => null,
                'published_at' => $postStatus === 'publish' ? ($article->published_at ?? now()) : null,
                'last_synced_at' => now(),
            ]);

            $this->syncTaxonomyBack($article, $result['body'] ?? []);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    public function delete(Article $article): array
    {
        try {
            $connection = $this->connectionOrFail($article);
        } catch (Throwable $e) {
            Log::warning('WordPress connection unavailable before delete', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->fail($article, ArticlePublishLog::ACTION_DELETE, $e->getMessage());
        }

        if (! $article->wp_post_id) {
            return $this->fail($article, ArticlePublishLog::ACTION_DELETE, 'The article was not on WordPress.');
        }

        $article->update(['wp_sync_status' => 'syncing']);

        $path = rtrim(config('wordpress_agent.plugin_routes.articles'), '/') . '/' . $article->wp_post_id;
        $signingPath = rtrim(config('wordpress_agent.signing_paths.articles'), '/') . '/' . $article->wp_post_id;

        $result = $this->call($connection, 'DELETE', $path, [], $signingPath);

        $this->logResult($article, ArticlePublishLog::ACTION_DELETE, $result);

        if ($result['ok']) {
            $article->update([
                'wp_sync_status' => 'not_synced',
                'publish_status' => null,
                'wp_post_id' => null,
                'wp_permalink' => null,
                'publish_error' => null,
                'published_at' => null,
                'last_synced_at' => now(),
            ]);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    public function syncStatus(Article $article): array
    {
        try {
            $connection = $this->connectionOrFail($article);
        } catch (Throwable $e) {
            Log::warning('WordPress connection unavailable before status sync', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->fail($article, ArticlePublishLog::ACTION_SYNC_STATUS, $e->getMessage());
        }

        if (! $article->wp_post_id) {
            return $this->fail($article, ArticlePublishLog::ACTION_SYNC_STATUS, 'The article is not published on WordPress.');
        }

        $article->update(['wp_sync_status' => 'syncing']);

        $path = rtrim(config('wordpress_agent.plugin_routes.articles'), '/') . '/' . $article->wp_post_id;
        $signingPath = rtrim(config('wordpress_agent.signing_paths.articles'), '/') . '/' . $article->wp_post_id;

        $result = $this->call($connection, 'GET', $path, [], $signingPath);

        $this->logResult($article, ArticlePublishLog::ACTION_SYNC_STATUS, $result);

        if ($result['ok']) {
            $responseBody = is_array($result['body'] ?? null) ? $result['body'] : [];
            $remoteStatus = $responseBody['status'] ?? null;

            $article->update([
                'wp_sync_status' => 'synced',
                'wp_permalink' => $responseBody['permalink'] ?? $article->wp_permalink,
                'publish_status' => $remoteStatus === 'publish' ? 'published' : ($remoteStatus ?? $article->publish_status),
                'last_synced_at' => now(),
            ]);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    protected function buildPayload(Article $article, string $postStatus = 'draft'): array
    {
        $sections = $article->sections;

        $galleryPlacement = $this->buildGalleryPlacement($sections, $article->image_gallery);

        $payload = [
            'payload_version' => self::PAYLOAD_VERSION,
            'article_id' => $article->id,
            'title' => $article->title,
            'post_status' => $postStatus,
            'content' => $sections->map(function ($section) use ($galleryPlacement) {
                $item = [
                    'heading' => $this->markdownInlineToHtml($section->heading),
                    'content' => $this->markdownToHtml($section->content),
                ];

                $images = $galleryPlacement['sections'][$section->position] ?? [];

                if (! empty($images)) {
                    $item['images'] = $images;
                }

                return $item;
            })->all(),
        ];

        if (! empty($galleryPlacement['lead'])) {
            $payload['lead_images'] = $galleryPlacement['lead'];
        }

        // فیلدهای اختیاری سئو: فقط وقتی مقدار واقعی دارن اضافه می‌شن. اگر یکی از این‌ها
        // در دیتابیس NULL باشد و همچنان به‌عنوان "null" در JSON فرستاده شود، سمت پلاگین
        // وردپرس validate_callback مربوط به همان فیلد شکست می‌خورد و طبق رفتار استاندارد
        // REST API وردپرس، کل درخواست (نه فقط همان فیلد) با خطای 400 رد می‌شود.
        // حذف کلید null-value قبل از ارسال، این ریسک را کاملاً از بین می‌برد.
        $optionalFields = [
            'meta_description' => $article->meta_description,
            'meta_title'       => $article->meta_title,
            'og_title'         => $article->og_title,
            'og_description'   => $article->og_description,
            'focus_keyword'    => $article->focus_keyword,
            'faq_schema'       => $article->faq_schema,
            'slug'             => $article->slug,
        ];

        foreach ($optionalFields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $payload[$key] = $value;
        }

        // دسته‌بندی: فقط وقتی AI موفق به تشخیص category_id شده (یعنی سایت
        // اصلاً categories داشته) این کلید اضافه می‌شود. اگر نباشد، پلاگین
        // طبق رفتار پیش‌فرض وردپرس روی «بدون دسته‌بندی» می‌ماند.
        if ($article->wp_category_id) {
            $payload['category_id'] = (int) $article->wp_category_id;
        }

        // تگ‌ها: ترکیب تگ‌های موجود انتخاب‌شده (با نام، نه id) + تگ‌های جدید
        // پیشنهادی. اگر مجموعاً چیزی نباشد، کلید tags اصلاً فرستاده نمی‌شود.
        $tagNames = $this->resolveTagNames($article);
        if (! empty($tagNames)) {
            $payload['tags'] = $tagNames;
        }

        $featuredImage = $this->buildFeaturedImagePayload($article);
        if ($featuredImage !== null) {
            $payload['featured_image'] = $featuredImage;
        }

        $banner = $this->buildBannerPayload($article);
        if ($banner !== null) {
            $payload['banner'] = $banner;
        }

        return $payload;
    }

    /**
     * تگ‌های نهایی این مقاله رو به شکل آرایه‌ای از نام‌ها (نه id) برمی‌گردونه.
     *
     * چرا نام و نه id؟ چون پلاگین سمت وردپرس، تگ‌ها رو با wp_set_post_tags()
     * ست می‌کنه که خودِ هسته‌ی وردپرس:
     *   - تگ‌هایی که از قبل با همین نام وجود دارن رو پیدا و reuse می‌کنه،
     *   - تگ‌هایی که وجود ندارن رو خودش می‌سازه.
     * پس نیازی نیست از سمت لاراول قبل از publish یه ریکوئست جدا برای
     * ساختن tag جدید بزنیم؛ فقط کافیه اسمش رو بفرستیم.
     *
     * wp_tag_ids روی article شامل idهایی از تگ‌های *موجود* سایت (که از
     * sites.tags کش شده) هست؛ این‌ها رو به نام تبدیل می‌کنیم و با
     * wp_new_tags (که از اول به‌صورت نام ذخیره شدن) ترکیب می‌کنیم.
     */
    protected function resolveTagNames(Article $article): array
    {
        $siteTags = collect($article->site->tags ?? []);
        $selectedIds = collect($article->wp_tag_ids ?? []);

        $existingNames = $selectedIds
            ->map(fn ($id) => $siteTags->firstWhere('id', $id)['name'] ?? null)
            ->filter();

        $newNames = collect($article->wp_new_tags ?? [])
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn ($name) => $name !== '');

        return $existingNames->merge($newNames)->unique()->values()->all();
    }

    /**
     * بعد از publish/update موفق، اگه پلاگین لیست نهایی تگ‌های واقعاً
     * assign‌شده رو (با id واقعی‌شون در وردپرس، شامل تگ‌های تازه‌ساخته‌شده)
     * برگردونده باشه، این‌ها رو به article و به کشِ sites.tags اضافه
     * می‌کنیم. این کار باعث می‌شه:
     *   - دفعه بعد که AI برای مقاله‌ی دیگه‌ای تگ تشخیص می‌ده، این تگ تازه
     *     ساخته‌شده رو به‌عنوان تگ «موجود» ببینه (به‌جای پیشنهاد دوباره‌ی
     *     یه تگ مشابه با اسم کمی متفاوت).
     *   - wp_new_tags روی خودِ این مقاله خالی بشه چون دیگه «جدید» نیستن.
     *
     * انتظار فرمت پاسخ پلاگین: 'tags' => [['id' => int, 'name' => string], ...]
     * اگر پلاگین این کلید رو برنگردونه، این متد کاملاً بی‌اثر می‌مونه —
     * یعنی هیچ نیازی نیست پلاگین فوراً همین امروز این فرمت رو پیاده کنه.
     */
    protected function syncTaxonomyBack(Article $article, array $responseBody): void
    {
        $returnedTags = $responseBody['tags'] ?? null;

        if (! is_array($returnedTags) || empty($returnedTags)) {
            return;
        }

        $normalized = collect($returnedTags)
            ->filter(fn ($t) => isset($t['id'], $t['name']))
            ->map(fn ($t) => ['id' => (int) $t['id'], 'name' => (string) $t['name']])
            ->values();

        if ($normalized->isEmpty()) {
            return;
        }

        $article->update([
            'wp_tag_ids' => $normalized->pluck('id')->all(),
            'wp_new_tags' => [],
        ]);

        $site = $article->site;
        $siteTags = collect($site->tags ?? []);
        $existingIds = $siteTags->pluck('id')->all();

        $merged = $siteTags
            ->merge($normalized->filter(fn ($t) => ! in_array($t['id'], $existingIds, true)))
            ->values()
            ->all();

        if (count($merged) !== $siteTags->count()) {
            $site->update(['tags' => $merged]);
        }
    }

    /**
     * تصاویر image_gallery رو بر اساس after_section گروه‌بندی می‌کنه.
     * position (روی article_sections) و after_section هر دو از 0 شروع
     * می‌شن و مستقیم با هم مقایسه می‌شن:
     * - هرچی after_section‌ش کمتر از position اولین section باشه (یا
     *   اصلاً section‌ای وجود نداشته باشه) میره تو 'lead' → قبل از همه‌ی
     *   بخش‌ها، بالای محتوا.
     * - در غیر این صورت به نزدیک‌ترین section‌ای که position‌ش <=
     *   after_section باشه می‌چسبه. اگه از آخرین section هم بزرگ‌تر باشه،
     *   به همون section آخر می‌چسبه (یعنی ته مقاله).
     * status=generated یا رکوردی که status آن در payload نیامده، بدون error،
     * و فایلی که واقعاً روی دیسک موجوده در نظر گرفته می‌شود. statusهای صریح
     * دیگر همچنان رد می‌شوند. ترتیب نهایی بر اساس after_section و بعد order.
     */
    protected function buildGalleryPlacement($sections, mixed $gallery): array
    {
        // image_gallery may be returned by Eloquent as an array, a JSON string
        // (when the model cast is missing), or an Illuminate Collection. The
        // featured image is a separate column, so this mismatch used to make
        // the featured image publish successfully while silently dropping every
        // in-article gallery image.
        if ($gallery instanceof \Illuminate\Support\Collection) {
            $gallery = $gallery->all();
        } elseif (is_string($gallery)) {
            $decoded = json_decode($gallery, true);
            $gallery = is_array($decoded) ? $decoded : [];
        }

        $gallery = is_array($gallery) ? $gallery : [];

        if (empty($gallery)) {
            return ['lead' => [], 'sections' => []];
        }

        usort($gallery, function ($a, $b) {
            $bySection = ($a['after_section'] ?? 0) <=> ($b['after_section'] ?? 0);

            return $bySection !== 0 ? $bySection : (($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        });

        $positions = $sections->pluck('position')->values()->all();
        $firstPosition = $positions[0] ?? null;

        $lead = [];
        $bySection = [];

        foreach ($gallery as $item) {
            // Older/generated gallery records may omit status entirely. In that
            // case the existing path and error checks are the source of truth;
            // only an explicit non-generated status should reject the image.
            if (isset($item['status']) && $item['status'] !== 'generated') {
                continue;
            }

            if (! empty($item['error'])) {
                continue;
            }

            $file = $this->encodeStoredFile(self::IMAGE_DISK, $item['path'] ?? null);

            if ($file === null) {
                continue;
            }

            $encoded = array_merge($file, [
                'alt'   => $item['alt'] ?? null,
                'order' => $item['order'] ?? null,
            ]);

            $afterSection = $item['after_section'] ?? 0;

            if ($firstPosition === null || $afterSection < $firstPosition) {
                $lead[] = $encoded;
                continue;
            }

            $target = $firstPosition;
            foreach ($positions as $position) {
                if ($position > $afterSection) {
                    break;
                }
                $target = $position;
            }

            $bySection[$target][] = $encoded;
        }

        return ['lead' => $lead, 'sections' => $bySection];
    }

    /**
     * یک فایل رو از دیسک می‌خونه و به شکل قابل‌ارسال (filename/mime/base64)
     * درمیاره. مسیر می‌تونه absolute (مثل image_path/گالری) یا نسبی به دیسک باشه.
     */
    protected function encodeStoredFile(string $disk, ?string $path): ?array
    {
        if (! $path) {
            return null;
        }

        $diskRoot = Storage::disk($disk)->path('');
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $normalizedRoot = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $diskRoot);

        if (str_starts_with($normalized, $normalizedRoot)) {
            $path = ltrim(substr($normalized, strlen($normalizedRoot)), '\\/');
            $path = str_replace('\\', '/', $path);
        }

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $contents = Storage::disk($disk)->get($path);
        $originalBytes = strlen($contents);
        [$contents, $mime, $filename] = $this->prepareImageForWordPress($contents, $path);

        if (strlen($contents) !== $originalBytes) {
            Log::info('WordPress image compressed for publish', [
                'path' => basename($path),
                'original_bytes' => $originalBytes,
                'publish_bytes' => strlen($contents),
                'mime' => $mime,
            ]);
        }

        return [
            'filename' => $filename,
            'mime'     => $mime,
            'base64'   => base64_encode($contents),
        ];
    }

    /**
     * Produces a compact publish copy for every image. The source file is never
     * modified. A two-image article therefore does not create a 4MB+ JSON body
     * that can time out at a proxy.
     */
    protected function prepareImageForWordPress(string $contents, string $path): array
    {
        $dimensions = @getimagesizefromstring($contents);
        $mime = is_array($dimensions) ? (string) ($dimensions['mime'] ?? '') : '';
        $filename = basename($path);
        $size = strlen($contents);
        $maxDimension = is_array($dimensions)
            ? max((int) ($dimensions[0] ?? 0), (int) ($dimensions[1] ?? 0))
            : 0;

        if ($size <= self::MAX_WORDPRESS_IMAGE_BYTES && $maxDimension <= self::MAX_WORDPRESS_IMAGE_DIMENSION) {
            return [$contents, $mime ?: 'image/jpeg', $filename];
        }

        // Prefer GD because it is already used by most Laravel image workers.
        // Unlike the old code, this branch is entered for a small-but-large-
        // dimension image too, so every image in a multi-image article is bounded.
        if ($mime !== 'image/gif' && function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            $source = @imagecreatefromstring($contents);
            if ($source !== false) {
                $width = imagesx($source);
                $height = imagesy($source);
                $scale = min(1, self::MAX_WORDPRESS_IMAGE_DIMENSION / max($width, $height));
                $targetWidth = max(1, (int) round($width * $scale));
                $targetHeight = max(1, (int) round($height * $scale));
                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                // Retry lower quality if the first WebP is still above the
                // per-image target.
                $converted = '';
                foreach ([78, 68, 58, 48] as $quality) {
                    ob_start();
                    imagewebp($canvas, null, $quality);
                    $candidate = (string) ob_get_clean();
                    if ($candidate !== '' && ($converted === '' || strlen($candidate) < strlen($converted))) {
                        $converted = $candidate;
                    }
                    if ($candidate !== '' && strlen($candidate) <= self::MAX_WORDPRESS_IMAGE_BYTES) {
                        $converted = $candidate;
                        break;
                    }
                }

                imagedestroy($canvas);
                imagedestroy($source);

                if ($converted !== '' && strlen($converted) <= self::MAX_WORDPRESS_IMAGE_BYTES) {
                    return [$converted, 'image/webp', pathinfo($filename, PATHINFO_FILENAME) . '.webp'];
                }
            }
        }

        // Imagick is a useful fallback on servers without GD and can also
        // resize GIFs while preserving their format/animation.
        if (class_exists('Imagick')) {
            try {
                $image = new \Imagick();
                $image->readImageBlob($contents);
                $image->setIteratorIndex(0);
                $image->thumbnailImage(self::MAX_WORDPRESS_IMAGE_DIMENSION, self::MAX_WORDPRESS_IMAGE_DIMENSION, true, true);
                $image->setImageFormat($mime === 'image/gif' ? 'gif' : 'webp');
                if ($mime !== 'image/gif') {
                    $image->setImageCompressionQuality(68);
                }
                $converted = (string) $image->getImagesBlob();
                $image->clear();
                $image->destroy();
                if ($converted !== '' && strlen($converted) <= self::MAX_WORDPRESS_IMAGE_BYTES) {
                    $outMime = $mime === 'image/gif' ? 'image/gif' : 'image/webp';
                    $outName = $mime === 'image/gif' ? $filename : pathinfo($filename, PATHINFO_FILENAME) . '.webp';
                    return [$converted, $outMime, $outName];
                }
            } catch (Throwable $e) {
                Log::warning('WordPress image compression via Imagick failed', [
                    'path' => basename($path),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException('Image compression failed; GD or Imagick must be enabled on the Laravel worker to publish large images.');
    }

    protected function buildFeaturedImagePayload(Article $article): ?array
    {
        if ($article->image_status !== 'generated') {
            return null;
        }

        $file = $this->encodeStoredFile(self::IMAGE_DISK, $article->image_path);

        if ($file === null) {
            return null;
        }

        return array_merge($file, [
            'alt' => $article->image_alt,
        ]);
    }

    /**
     * بنر یک فایل نیست؛ یک بلوک HTML آماده‌ست (با لوگو/اسم سایت داخلش).
     * فقط آخرین بنری که به مقاله attach شده در نظر گرفته می‌شه، و فقط
     * وقتی که واقعاً generated باشه و html داشته باشه.
     */
    protected function buildBannerPayload(Article $article): ?array
    {
        $banner = $article->latestBanner();

        if (! $banner || $banner->status !== Banner::STATUS_GENERATED || ! $banner->html) {
            return null;
        }

        return array_filter([
            'id'        => $banner->id,
            'html'      => $banner->html,
            'logo_url'  => $banner->logo_url,
            'site_name' => $banner->site_name,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function markdownToHtml(?string $text): string
    {
        if (! $text) {
            return '';
        }

        $html = trim((string) $this->markdown->convert($text)->getContent());

        if ($html === '') {
            return '';
        }

        // No forced direction: the WordPress theme already follows the site language.
        return '<div>' . $html . '</div>';
    }

    protected  function markdownInlineToHtml(?string $text): string
    {
        if (! $text) {
            return '';
        }

        $html = (string) $this->markdown->convert($text)->getContent();

        $html = trim($html);
        $html = preg_replace('/^<p>(.*)<\/p>$/s', '$1', $html);
        $html = trim($html ?? '');

        if ($html === '') {
            return '';
        }

        return $html;
    }

    protected function resolvePostStatus(Article $article): string
    {
        if ($article->wp_post_id) {
            return $article->publish_status === 'draft' ? 'draft' : 'publish';
        }

        // A stale wp_post_id may have just been cleared because the article was
        // moved to another WordPress site. Preserve the article's intended
        // published/draft state instead of silently falling back to the new
        // site's default setting during the automatic re-create.
        if ($article->publish_status === 'draft') {
            return 'draft';
        }

        if ($article->publish_status === 'published') {
            return 'publish';
        }

        $setting = $article->site->agentSetting
            ?? AgentSetting::firstOrCreate(['site_id' => $article->site->id]);

        return $setting->default_publish_status === 'publish' ? 'publish' : 'draft';
    }

    /**
     * Signed request to any other plugin endpoint (e.g. the site's existing posts);
     * same signing contract and return shape as call().
     */
    public function signedRequest(SiteConnection $connection, string $method, string $path, array $payload, string $signingPath): array
    {
        return $this->call($connection, $method, $path, $payload, $signingPath);
    }

    protected function call(
        SiteConnection $connection,
        string $method,
        string $path,
        array $payload,
        string $signingPath = ''
    ): array
    {
        if (! $connection->isConnected() || ! $connection->wp_url) {
            return [
                'ok' => false,
                'message' => 'WordPress site is not connected.',
            ];
        }

        $url = rtrim($connection->wp_url, '/') . $path;

        try {
            // The exact encoded string is both transmitted and signed.
            $body = empty($payload)
                ? ''
                : json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR
                );
        } catch (\JsonException $e) {
            Log::warning('WordPress payload encoding failed', [
                'article_id' => $payload['article_id'] ?? null,
                'error'      => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'message' => 'Building the article payload for WordPress failed.',
            ];
        }

        Log::info('WordPress agent payload prepared', [
            'article_id' => $payload['article_id'] ?? null,
            'method'     => strtoupper($method),
            'bytes'      => strlen($body),
        ]);

        $normalizedPath = ltrim($signingPath !== '' ? $signingPath : $path, '/');

        try {
            $headers = $this->signer->buildOutboundHeaders(
                $connection,
                $body,
                $method,
                $normalizedPath
            );

            [$statusCode, $responseBody, $transportError] = $this->sendDirect($url, $method, $headers, $body);

            if (null !== $transportError) {
                Log::warning('WordPress agent request failed', [
                    'url'   => $url,
                    'error' => $transportError,
                ]);

                return [
                    'ok'      => false,
                    'message' => $transportError,
                ];
            }

            $decodedBody = json_decode((string) $responseBody, true);
            $ok = $statusCode >= 200 && $statusCode < 300;

            if (! $ok) {
                $errorMessage = $this->formatWordPressError($statusCode, $decodedBody, $responseBody);

                Log::error('WordPress agent returned an HTTP error', [
                    'article_id'  => $payload['article_id'] ?? null,
                    'method'      => strtoupper($method),
                    'url'         => $url,
                    'status_code' => $statusCode,
                    'error'       => $errorMessage,
                    'response'    => is_array($decodedBody) ? $decodedBody : mb_substr((string) $responseBody, 0, 2000),
                ]);

                return [
                    'ok'          => false,
                    'message'     => $errorMessage,
                    'status_code' => $statusCode,
                    'body'        => is_array($decodedBody) ? $decodedBody : null,
                ];
            }

            return [
                'ok'          => true,
                'status_code' => $statusCode,
                'body'        => is_array($decodedBody) ? $decodedBody : null,
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress agent request failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sends the signed request to the site's wp_url.
     * Returns [status_code, raw_body, transport_error|null].
     */
    protected function sendDirect(string $url, string $method, array $headers, string $body): array
    {
        $request = Http::withHeaders($headers)
            ->withOptions(['allow_redirects' => false])
            ->timeout(90);

        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        $response = $request->send($method, $url);

        return [$response->status(), $response->body(), null];
    }

    protected function logResult(Article $article, string $action, array $result): void
    {
        $responseBody = is_array($result['body'] ?? null) ? $result['body'] : null;

        ArticlePublishLog::create([
            'article_id' => $article->id,
            'site_id' => $article->website_id,
            'action' => $action,
            'status' => $result['ok'] ? ArticlePublishLog::STATUS_SUCCESS : ArticlePublishLog::STATUS_FAILED,
            'wp_post_id' => $responseBody['wp_post_id'] ?? $article->wp_post_id,
            'response' => $responseBody,
            'error_message' => $result['ok'] ? null : ($result['message'] ?? null),
        ]);
    }

    /**
     * تبدیل پاسخ خطای وردپرس به پیامی که علت واقعی را به مشتری نشان می‌دهد.
     * پاسخ خام فقط برای لاگ نگه داشته می‌شود و در پیام نهایی محدود می‌گردد.
     */
    protected function formatWordPressError(int $statusCode, mixed $decodedBody, ?string $rawBody): string
    {
        $details = null;

        if (is_array($decodedBody)) {
            $details = $decodedBody['message']
                ?? ($decodedBody['code'] ?? null);

            if (isset($decodedBody['data']['status'])) {
                $details = ($details ? $details . ' — ' : '') . 'HTTP ' . (int) $decodedBody['data']['status'];
            }
        }

        if (! $details && is_string($rawBody) && trim($rawBody) !== '') {
            $details = trim(preg_replace('/\s+/', ' ', strip_tags($rawBody)) ?? '');
        }

        $details = $details ? mb_substr($details, 0, 500) : 'no details from WordPress';

        return "WordPress returned HTTP {$statusCode}: {$details}";
    }

    /**
     * A 404 is safe to recreate only when the remote response says the managed
     * article is missing. Other 404s (wrong route, blocked endpoint, etc.) must
     * remain visible as real failures.
     */
    protected function isManagedArticleNotFound(array $result): bool
    {
        if ((int) ($result['status_code'] ?? 0) !== 404) {
            return false;
        }

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $message = strtolower((string) ($body['message'] ?? $result['message'] ?? ''));

        return str_contains($message, 'managed article')
            && str_contains($message, 'not found');
    }

    protected function fail(Article $article, string $action, string $message): array
    {
        $result = ['ok' => false, 'message' => $message];
        $this->logResult($article, $action, $result);
        $article->update([
            'wp_sync_status' => 'failed',
            'publish_error' => $message,
        ]);

        return $result;
    }

    protected function connectionOrFail(Article $article): SiteConnection
    {
        $connection = $article->site->connection;

        if (! $connection) {
            throw new \RuntimeException('This site is not connected to WordPress yet.');
        }

        return $connection;
    }
}
