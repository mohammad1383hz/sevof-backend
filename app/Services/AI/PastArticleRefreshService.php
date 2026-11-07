<?php

namespace App\Services\AI;

use App\Exceptions\InsufficientCreditException;
use App\Jobs\GenerateArticleRefreshJob;
use App\Models\ArticleRefresh;
use App\Models\WordpressArticle;
use App\Services\SearchConsoleInsightsService;
use App\Services\SubscriptionService;
use App\Services\TokenUsageService;
use App\Services\Wp\PastPostsService;
use App\Support\PageUrl;
use App\Support\PostHtml;
use App\Support\RefreshBlocks;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * «آپدیت مقاله‌های گذشته»: به‌روزرسانی پست‌های قدیمی خود سایت (نه ساخته‌ی یوتیکس) روی HTML خام وردپرس.
 * همان موتور ویرایش پاراگرافی (یک فراخوانی AI)، مهارت نوشتن و حساب کریدیت مقاله‌های یوتیکس؛ تفاوت‌ها:
 * - متن از افزونه خوانده و به آن نوشته می‌شود (نه از جدول مقاله‌ها)
 * - تصویر، ویدیو، جدول، شورت‌کد، نشانه‌های ویرایشگر و آدرس لینک‌ها قبل از AI قفل می‌شوند
 * - اگر پست بعد از خواندن در وردپرس ویرایش شده باشد، روی آن نوشته نمی‌شود
 * - بدون داده‌ی سرچ کنسول هم کار می‌کند (بهبود بر اساس موضوع عنوان)
 */
class PastArticleRefreshService extends ArticleRefreshService
{
    /** پیام فارسی برای پستی که با صفحه‌ساز ساخته شده یا ویرایشش امن نیست. */
    public const NOT_EDITABLE_MESSAGES = [
        'elementor' => 'این مقاله با المنتور ساخته شده؛ متنش داخل صفحه‌ساز است و از اینجا ویرایش نمی‌شود.',
        'divi' => 'این مقاله با صفحه‌ساز دیوی ساخته شده و از اینجا ویرایش نمی‌شود.',
        'wpbakery' => 'این مقاله با صفحه‌ساز WPBakery ساخته شده و از اینجا ویرایش نمی‌شود.',
        'beaver' => 'این مقاله با صفحه‌ساز Beaver ساخته شده و از اینجا ویرایش نمی‌شود.',
        'oxygen' => 'این مقاله با صفحه‌ساز Oxygen ساخته شده و از اینجا ویرایش نمی‌شود.',
        'bricks' => 'این مقاله با صفحه‌ساز Bricks ساخته شده و از اینجا ویرایش نمی‌شود.',
        'thrive' => 'این مقاله با Thrive ساخته شده و از اینجا ویرایش نمی‌شود.',
        'empty' => 'این مقاله متنی برای به‌روزرسانی ندارد.',
        'too_large' => 'این مقاله برای به‌روزرسانی خودکار بیش از حد بزرگ است.',
    ];

    public function __construct(
        ArticleWriterService $writer,
        SearchConsoleInsightsService $insights,
        SubscriptionService $subscriptions,
        TokenUsageService $tokenUsage,
        protected PastPostsService $posts,
    ) {
        parent::__construct($writer, $insights, $subscriptions, $tokenUsage);
    }

    public static function notEditableMessage(?string $reason): string
    {
        return self::NOT_EDITABLE_MESSAGES[$reason] ?? 'این مقاله از اینجا قابل ویرایش نیست.';
    }

    public function startForPost(WordpressArticle $post): ArticleRefresh
    {
        $site = $post->site;
        $user = $site?->user;

        if (! $site || ! $user || ! $this->posts->connection($site)) {
            throw new RuntimeException('WordPress site is not connected; connect WordPress first.');
        }

        if ($post->managed !== false) {
            throw new RuntimeException('This post isn\'t in the site\'s past-articles list. Reload the list from the site.');
        }

        if ($post->editable === false) {
            throw new RuntimeException(self::notEditableMessage($post->not_editable_reason));
        }

        if (ArticleRefresh::where('wordpress_article_id', $post->id)->whereIn('status', ArticleRefresh::OPEN_STATUSES)->exists()) {
            throw new RuntimeException('An update for this article is already in progress.');
        }

        if (! $this->subscriptions->hasAvailableCredit($user)) {
            throw new InsufficientCreditException('You don\'t have enough credit to update the article. Please renew your plan.');
        }

        $hash = PageUrl::hash($post->url);
        $metrics = $hash ? ($this->insights->pageMetrics($site)->get($hash) ?? []) : [];
        $reason = $metrics !== [] ? ($this->insights->refreshReason($metrics)['reason'] ?? ArticleRefresh::REASON_MANUAL) : ArticleRefresh::REASON_MANUAL;

        $refresh = ArticleRefresh::create([
            'site_id' => $site->id,
            'source' => ArticleRefresh::SOURCE_WP_POST,
            'article_id' => null,
            'wordpress_article_id' => $post->id,
            'status' => ArticleRefresh::STATUS_QUEUED,
            'trigger' => ArticleRefresh::TRIGGER_MANUAL,
            'reason' => $reason,
            'metrics' => $metrics !== [] ? $metrics + ['potential_clicks' => $this->insights->refreshReason($metrics)['potential_clicks'] ?? null] : null,
            'queries' => $hash ? $this->insights->queriesForPage($site, $hash, self::MAX_QUERIES)->all() : [],
        ]);

        GenerateArticleRefreshJob::dispatch($refresh->id);

        return $refresh;
    }

    /**
     * تخمین کریدیت از روی تعداد کلمه (متن کامل هنوز خوانده نشده).
     *
     * @return array{min:int, max:int}
     */
    public function estimateForWords(string $textModel, int $words): array
    {
        return $this->estimateForChars($textModel, max(600, $words * 6));
    }

    public function generate(ArticleRefresh $refresh): void
    {
        $post = $refresh->wordpressArticle()->with('site.user')->firstOrFail();
        $site = $post->site;
        $user = $site->user;

        $this->begin($refresh, $site, $user);
        $this->setStage($refresh, self::STAGE_READING);

        $connection = $this->posts->connection($site) ?? throw new RuntimeException('WordPress site is not connected.');
        $remote = $this->posts->fetch($connection, (int) $post->wp_post_id);

        // قبل از هر هزینه‌ای: پستی که ویرایشش امن نیست، اصلاً به AI نمی‌رسد
        if (empty($remote['editable'])) {
            $post->update(['editable' => false, 'not_editable_reason' => $remote['not_editable_reason'] ?? null]);

            throw new RuntimeException(self::notEditableMessage($remote['not_editable_reason'] ?? null));
        }

        $seoPlugin = $remote['seo']['plugin'] ?? null;
        $sections = PostHtml::split((string) ($remote['content'] ?? ''));

        $refresh->update(['before' => [
            'content' => (string) $remote['content'],
            'modified' => (string) $remote['modified'],
            'seo_plugin' => $seoPlugin,
            // عنوان/توضیح گوگل فقط وقتی افزونه‌ی سئو هست که بشود نوشت و دوباره برگرداند
            'meta' => $seoPlugin ? [
                'meta_title' => (string) ($remote['seo']['meta_title'] ?? ''),
                'meta_description' => (string) ($remote['seo']['meta_description'] ?? ''),
            ] : [],
            'sections' => array_map(fn (array $s) => ['id' => $s['id'], 'type' => $s['type'], 'heading' => $s['heading'], 'content' => $s['html']], $sections),
        ]]);

        $documentSections = array_map(function (array $s) {
            [$masked, $map] = PostHtml::mask($s['html']);

            return [
                'id' => $s['id'],
                'type' => $s['type'],
                'heading' => $s['heading'],
                'masked' => $masked,
                'restore' => fn (string $text) => PostHtml::restore($text, $map),
            ];
        }, $sections);

        $beforeMeta = $refresh->before['meta'];
        $context = $this->seoContext(
            $site,
            $post->url,
            (string) $post->title,
            null,
            array_column($sections, 'heading'),
            (string) $remote['content'],
            RefreshBlocks::HTML,
            $post->modified_at ?? $post->published_at,
            (array) $refresh->queries,
            $this->posts->storeAudit($post, $remote, $site->domain),
        );
        $result = $this->proposeEdits(
            $refresh,
            ['title' => $post->title, 'focus_keyword' => null, 'meta_title' => $beforeMeta['meta_title'] ?? null, 'meta_description' => $beforeMeta['meta_description'] ?? null],
            $documentSections,
            RefreshBlocks::HTML,
            $this->textModelForUser($user),
            $context,
        );

        $this->finish($refresh, $result, $seoPlugin ? $this->cleanMeta($result['plan'], $beforeMeta) : []);
    }

    /** نسخه‌ی تازه روی سایت؛ فقط اگر پست از لحظه‌ی خواندن در وردپرس تغییر نکرده باشد. */
    public function apply(ArticleRefresh $refresh): void
    {
        $before = (array) $refresh->before;
        $after = (array) $refresh->after;
        $replacements = collect($after['sections'] ?? [])->pluck('content', 'id')->all();
        $content = PostHtml::join(array_map(
            fn (array $s) => $replacements[$s['id']] ?? $s['content'],
            $before['sections'] ?? [],
        ));

        $this->push($refresh, $content, $after['meta'] ?? [], (string) ($before['modified'] ?? ''), ArticleRefresh::STATUS_APPLIED, ArticleRefresh::STATUS_READY);
    }

    /** نسخه‌ی قبلی دقیقاً همان‌طور که بود (متن و عنوان/توضیح گوگل) برمی‌گردد. */
    public function revert(ArticleRefresh $refresh): void
    {
        $before = (array) $refresh->before;
        $after = (array) $refresh->after;
        $meta = array_intersect_key((array) ($before['meta'] ?? []), (array) ($after['meta'] ?? []));

        $this->push($refresh, (string) ($before['content'] ?? ''), $meta, (string) ($after['remote_modified'] ?? ''), ArticleRefresh::STATUS_REVERTED, ArticleRefresh::STATUS_APPLIED);
    }

    protected function push(ArticleRefresh $refresh, string $content, array $meta, string $expectedModified, string $successStatus, string $failStatus): void
    {
        $post = $refresh->wordpressArticle()->with('site')->firstOrFail();
        $connection = $this->posts->connection($post->site);

        if (! $connection || $content === '' || $expectedModified === '') {
            $refresh->update(['status' => $failStatus, 'stage' => null, 'error' => 'WordPress is not connected or this update is incomplete.']);

            return;
        }

        $this->setStage($refresh, self::STAGE_PUBLISHING);
        $result = $this->posts->update($connection, (int) $post->wp_post_id, ['content' => $content, 'expected_modified' => $expectedModified] + $meta);

        if (! ($result['ok'] ?? false)) {
            $conflict = ($result['status_code'] ?? 0) === 409;
            $refresh->update([
                'status' => $failStatus,
                'stage' => null,
                'error' => $conflict
                    ? 'این مقاله بعد از ساخت پیشنهاد در وردپرس ویرایش شده است. برای اینکه تغییرات شما پاک نشود، کاری انجام نشد.'
                    : 'انتشار روی سایت ناموفق بود: '.Str::limit((string) ($result['message'] ?? 'Unknown error'), 400),
            ]);

            return;
        }

        $modified = (string) ($result['body']['modified'] ?? '');
        $refresh->update([
            'status' => $successStatus,
            'stage' => null,
            'error' => null,
            'after' => array_merge((array) $refresh->after, ['remote_modified' => $modified]),
            'applied_at' => $successStatus === ArticleRefresh::STATUS_APPLIED ? now() : $refresh->applied_at,
        ]);
        $post->update(['modified_at' => now()]);
    }
}
