<?php

namespace App\Services\Wp;

use App\Exceptions\WordPressPluginOutdatedException;
use App\Jobs\AuditPastPostsJob;
use App\Jobs\SyncPastPostsJob;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\WordpressArticle;
use App\Support\ContentAudit;
use App\Support\RefreshBlocks;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * پست‌های قدیمی خود سایت (که با یوتیکس ساخته نشده‌اند) از طریق افزونه‌ی وردپرس:
 * list, read the full content and update. Every request is signed.
 */
class PastPostsService
{
    public const MIN_PLUGIN_VERSION = '1.0.0';

    protected const PATH = '/index.php?rest_route=/sevof-connector/v1/existing-posts';

    protected const SIGNING_PATH = 'sevof-connector/v1/existing-posts';

    /** Smaller pages = lighter responses, less exposed to site timeouts. */
    protected const PER_PAGE = 25;

    /** حداکثر ۵۰۰۰ پست. */
    protected const MAX_PAGES = 200;

    /** هر Job حداکثر این‌قدر کار می‌کند و بقیه را به Job بعدی می‌سپارد (زیر سقف زمان صف). */
    protected const BATCH_SECONDS = 150;

    public function __construct(protected ArticlePublishService $publisher) {}

    public function connection(Site $site): ?SiteConnection
    {
        $connection = $site->connection;

        return $connection && $connection->isConnected() && $connection->wp_url ? $connection : null;
    }

    /**
     * شروع خواندن همه‌ی پست‌های منتشرشده‌ی غیر یوتیکس. کار در چند Job پشت سر هم انجام می‌شود
     * (هر کدام چند صفحه)، تا سایت‌های بزرگ یا کند هم به سقف زمان صف نخورند و نیمه‌کاره نمانند.
     */
    public function startSync(Site $site): void
    {
        $connection = $this->connection($site) ?? throw new RuntimeException('WordPress site is not connected.');
        $connection->update([
            'past_posts_sync_status' => 'queued',
            'past_posts_sync_error' => null,
            'past_posts_sync_started_at' => now(),
            'past_posts_total' => null,
            'past_posts_fetched' => 0,
        ]);

        SyncPastPostsJob::dispatch($site->id, 1);
    }

    /**
     * یک دسته از صفحه‌ها را می‌خواند و ذخیره می‌کند.
     *
     * @return int|null صفحه‌ی بعدی برای Job بعد، یا null یعنی همه خوانده شد
     */
    public function syncBatch(Site $site, int $fromPage): ?int
    {
        $connection = $this->connection($site) ?? throw new RuntimeException('WordPress site is not connected.');
        $connection->update(['past_posts_sync_status' => 'syncing']);
        $startedAt = microtime(true);
        $page = $fromPage;

        try {
            do {
                $body = $this->requestWithRetry($connection, self::PATH.'&page='.$page.'&per_page='.self::PER_PAGE);
                $rows = collect((array) ($body['items'] ?? []))
                    ->filter(fn ($item) => is_array($item) && ! empty($item['id']) && ! empty($item['link']))
                    ->map(fn (array $item) => $this->row($site, $item));

                if ($rows->isNotEmpty()) {
                    WordpressArticle::upsert(
                        $rows->values()->all(),
                        ['site_id', 'wp_post_id'],
                        ['title', 'url', 'published_at', 'modified_at', 'word_count', 'editable', 'not_editable_reason', 'managed', 'plugin_synced_at', 'updated_at'],
                    );
                }

                $pages = min((int) ($body['pages'] ?? 1), self::MAX_PAGES);
                $connection->update([
                    'past_posts_total' => min((int) ($body['total'] ?? 0), self::MAX_PAGES * self::PER_PAGE),
                    'past_posts_fetched' => (int) $connection->past_posts_fetched + $rows->count(),
                ]);
                $page++;
            } while ($page <= $pages && microtime(true) - $startedAt < self::BATCH_SECONDS);

            if ($page <= $pages) {
                return $page;
            }

            $this->finishSync($site, $connection);

            return null;
        } catch (Throwable $e) {
            $connection->update([
                'past_posts_sync_status' => $e instanceof WordPressPluginOutdatedException ? 'outdated' : 'failed',
                'past_posts_sync_error' => Str::limit($e->getMessage(), 1000),
            ]);

            throw $e;
        }
    }

    /** پستی که در این دور دیده نشد (حذف یا پیش‌نویس شده) از فهرست کنار می‌رود ولی پاک نمی‌شود. */
    protected function finishSync(Site $site, SiteConnection $connection): void
    {
        $startedAt = $connection->past_posts_sync_started_at ?? now()->subDay();

        WordpressArticle::where('site_id', $site->id)
            ->where('managed', false)
            ->where(fn ($q) => $q->whereNull('plugin_synced_at')->orWhere('plugin_synced_at', '<', $startedAt))
            ->update(['managed' => null]);

        $connection->update(['past_posts_sync_status' => 'done', 'past_posts_synced_at' => now(), 'past_posts_sync_error' => null]);

        // متن پست‌ها در فهرست نیست؛ بررسی لینک داخلی و تاریخ‌ها در پس‌زمینه یکی‌یکی انجام می‌شود
        AuditPastPostsJob::dispatch($site->id);
    }

    /** بررسی سئوی متن پست (لینک داخلی، سال‌های قدیمی، طول) روی همان ردیف ذخیره می‌شود. */
    public function storeAudit(WordpressArticle $post, array $remote, ?string $siteDomain): array
    {
        $audit = ContentAudit::audit((string) ($remote['content'] ?? ''), RefreshBlocks::HTML, $siteDomain);
        $post->update(['audit' => $audit, 'audited_at' => now()]);

        return $audit;
    }

    /**
     * یک دسته از پست‌هایی که هنوز بررسی نشده‌اند یا بعد از بررسی در وردپرس ویرایش شده‌اند.
     *
     * @return bool پست دیگری برای بررسی مانده است
     */
    public function auditBatch(Site $site, int $limit = 20): bool
    {
        $connection = $this->connection($site);

        if (! $connection) {
            return false;
        }

        $posts = WordpressArticle::where('site_id', $site->id)
            ->where('managed', false)
            ->where('editable', true)
            ->where(fn ($q) => $q->whereNull('audited_at')->orWhereColumn('audited_at', '<', 'modified_at'))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $startedAt = microtime(true);

        foreach ($posts as $post) {
            try {
                $this->storeAudit($post, $this->fetch($connection, (int) $post->wp_post_id), $site->domain);
            } catch (WordPressPluginOutdatedException) {
                return false;
            } catch (Throwable) {
                // تا ویرایش بعدی پست دوباره امتحان نمی‌شود؛ نتیجه «نامعلوم» می‌ماند
                $post->update(['audited_at' => now()]);
            }

            if (microtime(true) - $startedAt > self::BATCH_SECONDS) {
                return true;
            }
        }

        return $posts->count() === $limit;
    }

    /** A transient network error is retried once; an outdated plugin is reported right away. */
    protected function requestWithRetry(SiteConnection $connection, string $path): array
    {
        try {
            return $this->request($connection, 'GET', $path, self::SIGNING_PATH);
        } catch (WordPressPluginOutdatedException $e) {
            throw $e;
        } catch (Throwable) {
            sleep(3);

            return $this->request($connection, 'GET', $path, self::SIGNING_PATH);
        }
    }

    /** متن خام و عنوان/توضیح گوگل فعلی یک پست. */
    public function fetch(SiteConnection $connection, int $wpPostId): array
    {
        return $this->request($connection, 'GET', self::PATH.'/'.$wpPostId, self::SIGNING_PATH.'/'.$wpPostId);
    }

    /**
     * @param  array{content:string, expected_modified:string, meta_title?:string, meta_description?:string}  $payload
     * @return array{ok:bool, status_code?:int, body?:array|null, message?:string} خروجی خام، تا 409 (تغییر هم‌زمان) جدا دیده شود
     */
    public function update(SiteConnection $connection, int $wpPostId, array $payload): array
    {
        return $this->publisher->signedRequest($connection, 'PUT', self::PATH.'/'.$wpPostId, $payload, self::SIGNING_PATH.'/'.$wpPostId);
    }

    protected function request(SiteConnection $connection, string $method, string $path, string $signingPath, array $payload = []): array
    {
        $result = $this->publisher->signedRequest($connection, $method, $path, $payload, $signingPath);

        if ($result['ok'] ?? false) {
            return (array) ($result['body'] ?? []);
        }

        // افزونه‌ی قدیمی این مسیر را نمی‌شناسد
        if (($result['status_code'] ?? 0) === 404 && ($result['body']['code'] ?? '') === 'rest_no_route') {
            throw new WordPressPluginOutdatedException(self::MIN_PLUGIN_VERSION);
        }

        if (($result['status_code'] ?? 0) === 404) {
            throw new RuntimeException('This post is no longer published on the site.');
        }

        throw new RuntimeException('Could not reach the site: '.Str::limit((string) ($result['message'] ?? 'Unknown error'), 300));
    }

    protected function row(Site $site, array $item): array
    {
        $now = now();

        return [
            // upsert رویداد creating ندارد؛ برای ردیف موجود token در لیست update نیست و عوض نمی‌شود
            'token' => WordpressArticle::newPublicToken(),
            'site_id' => $site->id,
            'wp_post_id' => (int) $item['id'],
            'title' => Str::limit(trim((string) ($item['title'] ?? '')) ?: '(untitled)', 250, ''),
            'url' => (string) $item['link'],
            'published_at' => self::gmt($item['date'] ?? null),
            'modified_at' => self::gmt($item['modified'] ?? null),
            'word_count' => max(0, (int) ($item['word_count'] ?? 0)),
            'editable' => (bool) ($item['editable'] ?? false),
            'not_editable_reason' => isset($item['not_editable_reason']) ? Str::limit((string) $item['not_editable_reason'], 30, '') : null,
            'managed' => false,
            'plugin_synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** زمان GMT وردپرس («0000-00-00 00:00:00» یعنی نامعلوم). */
    protected static function gmt(?string $value): ?Carbon
    {
        if (! $value || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
