<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** آخرین نتیجه‌ی بررسی ایندکس یک مقاله‌ی تولیدشده در گوگل (فقط پنل ادمین). */
class ArticleIndexCheck extends Model
{
    public const TYPE_ARTICLE = 'article';
    public const TYPE_PRODUCT_ARTICLE = 'product_article';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_indexed' => 'boolean',
            'published_at' => 'datetime',
            'last_crawl_at' => 'datetime',
            'first_indexed_at' => 'datetime',
            'checked_at' => 'datetime',
            'checks_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** برچسب فارسی وضعیت پوشش گوگل (پاسخ API به انگلیسی گرفته می‌شود تا ثابت بماند). */
    /** مقاله‌ی تازه‌تر از این تعداد روز معمولاً فقط منتظر نوبت گوگل است. */
    public const NEW_ARTICLE_DAYS = 14;

    /**
     * دلیل ایندکس‌نشدن (یا خطای بررسی) به زبان ساده، با کاری که باید کرد.
     * اول مسدودی‌های قطعی (robots، noindex، خطای صفحه) چک می‌شوند، بعد وضعیت پوشش گوگل.
     *
     * @return array{title:string, fix:string, is_new:bool}|null  is_new: تازه منتشر شده و شاید فقط منتظر نوبت گوگل است
     */
    public function reason(): ?array
    {
        if ($this->error && $this->is_indexed === null) {
            return str_contains((string) $this->error, 'not under the Search Console property')
                ? ['title' => 'Article URL doesn\'t match the Search Console property', 'fix' => 'Make the article\'s domain (with/without www and https) match the Search Console property, or connect a domain property.', 'is_new' => false]
                : ['title' => 'Google check failed', 'fix' => 'Usually temporary; it will be checked again tomorrow. If it repeats, check the site\'s Search Console connection.', 'is_new' => false];
        }

        if ($this->is_indexed !== false) {
            return null;
        }

        $blocked = match (true) {
            $this->robots_txt_state === 'DISALLOWED' || $this->page_fetch_state === 'BLOCKED_ROBOTS_TXT' || $this->indexing_state === 'BLOCKED_BY_ROBOTS_TXT'
                => ['Blocked by robots.txt', 'Don\'t Disallow your article paths in robots.txt.'],
            $this->indexing_state === 'BLOCKED_BY_META_TAG'
                => ['Article has a noindex tag', 'Turn off noindex for this post in your SEO plugin (Yoast or Rank Math) or WordPress settings.'],
            $this->indexing_state === 'BLOCKED_BY_HTTP_HEADER'
                => ['Has an X-Robots-Tag: noindex header', 'Your server or a security/cache plugin sends this header; remove it for article pages.'],
            in_array($this->page_fetch_state, ['NOT_FOUND', 'BLOCKED_4XX'], true)
                => ['Page not found for Google (404)', 'Open the article URL; if it was deleted or moved, publish it again.'],
            $this->page_fetch_state === 'SOFT_404'
                => ['Google sees the page as empty (soft 404)', 'Make sure the full article text is shown on the page and your theme doesn\'t hide it.'],
            in_array($this->page_fetch_state, ['ACCESS_DENIED', 'ACCESS_FORBIDDEN'], true)
                => ['Google isn\'t allowed to see the page (401/403)', 'A firewall, Cloudflare or a security plugin is blocking Googlebot; allow it.'],
            in_array($this->page_fetch_state, ['SERVER_ERROR', 'INTERNAL_CRAWL_ERROR'], true)
                => ['Server returned an error to Google (5xx)', 'Check your hosting speed and stability; Google will try again later.'],
            $this->page_fetch_state === 'REDIRECT_ERROR'
                => ['Broken redirect', 'Fix redirect chains or loops on the article URL.'],
            default => null,
        };

        if ($blocked) {
            return ['title' => $blocked[0], 'fix' => $blocked[1], 'is_new' => $this->isNew()];
        }

        $byCoverage = [
            'Crawled - currently not indexed' => ['Google crawled the page but didn\'t index it', 'Google didn\'t find the content valuable or distinct enough. Link to it from other articles and make it unique if it resembles another page.'],
            'Discovered - currently not indexed' => ['Google knows the URL but hasn\'t crawled it yet', 'Usually this just needs time. Internal links from popular pages and a sitemap entry speed it up.'],
            'URL is unknown to Google' => ['Google hasn\'t found this page yet', 'Make sure the article is in the sitemap and linked from another page; click "Request indexing" in Search Console.'],
            'Duplicate without user-selected canonical' => ['Google sees the page as a duplicate', 'Give the article a self-referencing canonical tag and make sure there is no similar page on the site.'],
            'Duplicate, Google chose different canonical than user' => ['Google chose another page as canonical', 'The article resembles another page; make the content distinct or remove the duplicate.'],
            'Alternate page with proper canonical tag' => ['Canonical points to another page', 'The article\'s canonical tag must point to its own URL.'],
            'Page with redirect' => ['Article URL redirects', 'Use the article\'s final URL in the sitemap and links.'],
            'Not found (404)' => ['Page not found for Google (404)', 'Open the article URL; if it was deleted or moved, publish it again.'],
            'Soft 404' => ['Google sees the page as empty (soft 404)', 'Make sure the full article text is shown on the page.'],
        ];

        if (isset($byCoverage[$this->coverage_state])) {
            [$title, $fix] = $byCoverage[$this->coverage_state];
        } else {
            $title = $this->coverageLabel() ?? 'Google gave no reason';
            $fix = 'See details in Search Console ("View in Search Console" link).';
        }

        return ['title' => $title, 'fix' => $fix, 'is_new' => $this->isNew()];
    }

    public function isNew(): bool
    {
        return $this->published_at !== null && $this->published_at->gt(now()->subDays(self::NEW_ARTICLE_DAYS));
    }

    public function coverageLabel(): ?string
    {
        $state = (string) $this->coverage_state;

        if ($state === '') {
            return null;
        }

        $labels = [
            'Submitted and indexed' => 'Submitted and indexed',
            'Indexed, not submitted in sitemap' => 'Indexed (not in sitemap)',
            'Indexed, though blocked by robots.txt' => 'Indexed though blocked by robots.txt',
            'Crawled - currently not indexed' => 'Crawled, currently not indexed',
            'Discovered - currently not indexed' => 'Discovered, not yet crawled',
            'URL is unknown to Google' => 'Google doesn\'t know this URL yet',
            'Duplicate without user-selected canonical' => 'Duplicate without user-selected canonical',
            'Duplicate, Google chose different canonical than user' => 'Duplicate; Google chose a different canonical',
            'Alternate page with proper canonical tag' => 'Alternate page with proper canonical',
            "Excluded by 'noindex' tag" => 'Has a noindex tag',
            'Excluded by ‘noindex’ tag' => 'Has a noindex tag',
            'Blocked by robots.txt' => 'Blocked by robots.txt',
            'Page with redirect' => 'Redirects',
            'Not found (404)' => 'Not found (404)',
            'Soft 404' => 'Soft 404',
            'Server error (5xx)' => 'Server error (5xx)',
            'Blocked due to unauthorized request (401)' => 'Access denied (401)',
            'Blocked due to access forbidden (403)' => 'Access denied (403)',
        ];

        return $labels[$state] ?? $state;
    }
}
