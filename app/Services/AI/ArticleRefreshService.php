<?php

namespace App\Services\AI;

use App\Exceptions\InsufficientCreditException;
use App\Jobs\ApplyArticleRefreshJob;
use App\Jobs\GenerateArticleRefreshJob;
use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\ArticleRefresh;
use App\Models\ArticleSection;
use App\Models\TokenUsage;
use App\Models\User;
use App\Services\AI\Concerns\PolishesLines;
use App\Services\ArticlePublisherResolver;
use App\Services\InternalLinkCandidates;
use App\Services\SearchConsoleInsightsService;
use App\Services\SubscriptionService;
use App\Services\TokenUsageService;
use App\Support\ContentAudit;
use App\Support\ContentLanguage;
use App\Support\PageUrl;
use App\Support\PersianText;
use App\Support\PersianWritingSkill;
use App\Support\PostHtml;
use App\Support\RefreshBlocks;
use App\Support\RefreshLinks;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * به‌روزرسانی مقاله‌ی منتشرشده با داده‌ی واقعی سرچ کنسول.
 *
 * فقط یک فراخوانی AI: مدل کل مقاله را با پاراگراف‌های شماره‌دار می‌بیند و فقط پاراگراف‌هایی را که باید
 * عوض یا اضافه شوند (به‌همراه عنوان و توضیح گوگل) برمی‌گرداند. هزینه‌ی خروجی به اندازه‌ی تغییرات است،
 * نه بازنویسی کامل بخش‌ها، و پاراگراف‌های دست‌نخورده بایت‌به‌بایت همان می‌مانند.
 * بعد: بررسی ایمنی هر تغییر با کد، اصلاح ارزان جمله‌ها (فارسی)، پیش‌نمایش، انتشار و امکان برگرداندن.
 * همه‌ی مصرف توکن با article_refresh_id ثبت می‌شود تا کریدیت هر به‌روزرسانی دقیق معلوم باشد.
 */
class ArticleRefreshService
{
    use PolishesLines;

    /** حداکثر تعداد تغییر پاراگرافی در یک به‌روزرسانی. */
    public const MAX_EDITS = 10;

    protected const MAX_QUERIES = 15;

    /** بخش بلندتر از این فقط برای فهم ساختار به AI نشان داده می‌شود و ویرایش نمی‌شود. */
    protected const MAX_SECTION_CHARS = 9000;

    /** سقف کل متنی که به AI فرستاده می‌شود (کنترل هزینه‌ی ورودی). */
    protected const MAX_DOCUMENT_CHARS = 30000;

    /** تخمین توکن برای متن فارسی/انگلیسی (کمی محتاطانه، تا تخمین کریدیت کمتر از واقعی نشود). */
    protected const CHARS_PER_TOKEN = 2.8;

    protected const POLISH_SEPARATOR = '@@BLOCK@@';

    public const STAGE_READING = 'reading';

    public const STAGE_WRITING = 'writing';

    public const STAGE_CHECKING = 'checking';

    public const STAGE_PUBLISHING = 'publishing';

    protected const EDIT_SYSTEM = <<<'PROMPT'
شما ویراستار ارشد سئو هستید. یک مقاله‌ی منتشرشده را با پاراگراف‌های شماره‌دار ([B1]، [B2]، ...) و جستجوهای واقعی گوگل که این صفحه برایشان نمایش داده می‌شود (از سرچ کنسول خود سایت) می‌بینید. با کمترین تغییر، مقاله را برای خواننده و همین جستجوها کامل‌تر و به‌روزتر کنید.

دلیل به‌روزرسانی (REASON):
- near_first_page: صفحه در صفحه‌ی دوم گوگل است. جستجوهای مهمی که هنوز جواب ندارند را پوشش بده و مقدمه مستقیم به جستجوی اصلی جواب بدهد.
- declining: کلیک‌ها کم شده. چیزهایی که ممکن است قدیمی شده باشند را به‌روز و کلی کن (بدون ساختن واقعیت)، پاراگراف‌های مربوط به جستجوهای مهم را قوی‌تر کن و عنوان گوگل را جذاب‌تر کن.
- low_ctr: رتبه خوب است ولی کم کلیک می‌خورد. اهرم اصلی meta_title و meta_description است؛ متن را فقط اگر جایی آشکارا جستجوی اصلی را جواب نمی‌دهد تغییر بده.
- manual: کاربر خودش خواسته؛ بهترین بهبودها را انتخاب کن (اگر داده‌ی سرچ کنسول نیست، بر اساس موضوع عنوان: جواب‌های پایه‌ای که نیست، بخش‌های مبهم یا قدیمی، مقدمه‌ی روشن).
در هر دلیلی، ایرادهای SEO CHECK (بررسی خودکار متن) را هم تا جایی که بدون ساختن واقعیت ممکن است رفع کن.

به‌روز بودن نسبت به امروز (TODAY) — مهم:
- همه‌ی چیزهای وابسته به زمان را پیدا کن: سال‌ها، «امسال»، «سال گذشته»، «جدیدترین»، «به‌تازگی»، نسخه‌ها، قانون‌ها، قیمت‌ها و آمار.
- سال قدیمی‌تر از سال جاری که طوری آمده که انگار وضعیت فعلی است: اگر جمله با سال جاری هم درست می‌ماند، سال را به سال جاری عوض کن؛ وگرنه جمله را بدون سال و کلی بنویس. سالی که اتفاقی تاریخی را می‌گوید (مثل سال تأسیس) درست است؛ دست نزن.
- اگر عنوان گوگل (meta_title) سال گذشته دارد و موضوع همیشگی است، سال جاری را بگذار. سال تازه فقط وقتی اضافه کن که موضوع واقعاً به سال وابسته است (مثل «بهترین ... سال جاری»).
- از خودت قیمت، آمار، نسخه یا خبر تازه نساز؛ اگر واقعیت فعلی را نمی‌دانی، کلی و درست بنویس.

لینک داخلی — یکی از مهم‌ترین کارهای این به‌روزرسانی:
- فقط به صفحه‌های فهرست INTERNAL LINK CANDIDATES (صفحه‌های همین سایت) لینک بده و فقط وقتی موضوع آن صفحه دقیقاً به همان پاراگراف مربوط است و خواننده با کلیک درباره‌ی همان حرف چیز بیشتری یاد می‌گیرد.
- شکل لینک فقط [[Ln|متن لینک]] داخل یک جمله است (Ln شماره‌ی صفحه در فهرست). هیچ آدرس، تگ a یا لینک دیگری خودت ننویس.
- متن لینک ۲ تا ۶ کلمه و توصیف موضوع همان صفحه باشد، نه «اینجا»، «کلیک کنید» یا «این مقاله». متن هر لینک فرق کند و کلمه‌ی کلیدی اصلی همین مقاله را متن لینکِ صفحه‌ی دیگری نکن.
- هر صفحه حداکثر یک بار، در کل حداکثر {MAX_LINKS} لینک تازه، هیچ لینکی در تیتر. بهتر است لینک‌ها در نیمه‌ی اول مقاله و در پاراگرافی باشند که همان موضوع را می‌گوید؛ مقاله‌های تازه‌ی یوتیکس (new) اولویت دارند.
- اگر SEO CHECK می‌گوید لینک داخلی کم است، لینک دادن اولویت دارد: می‌شود یک پاراگراف موجود را با کمترین تغییر و یک جمله‌ی طبیعی بازنویسی کرد (replace) تا لینک در آن بنشیند.
- اگر هیچ صفحه‌ای واقعاً مرتبط نیست، لینک نگذار؛ لینک بی‌ربط از نبودنش بدتر است.

جستجوهای سرچ کنسول:
- جستجویی که «question» دارد سؤال واقعی کاربران است: اگر مقاله جوابش را مستقیم نمی‌دهد، یک جواب کوتاه و مستقیم اضافه کن (در بخش پرسش‌ها اگر هست، وگرنه در پاراگراف مرتبط).
- جستجویی که «main page for this search is another page of this site» دارد، صفحه‌ی اصلی‌اش جای دیگری از همین سایت است. این مقاله را برای آن جستجو قوی‌تر نکن، چون دو صفحه‌ی سایت با هم رقابت می‌کنند و هر دو ضعیف می‌شوند؛ اگر آن صفحه در INTERNAL LINK CANDIDATES هست، از جمله‌ی مرتبط به آن لینک بده.

خروجی:
- edits: حداکثر {MAX} تغییر، فقط جایی که اثر واقعی دارد. هر تغییر:
  - action = replace: پاراگراف block را با نسخه‌ی بهتر عوض کن (همان موضوع، کامل‌تر یا روشن‌تر). فقط پاراگرافی که متن دارد.
  - action = insert_after: یک پاراگراف تازه بعد از block اضافه کن (مثلاً جواب جستجویی که در مقاله نیست، یا یک سؤال و جواب کوتاه در بخش پرسش‌ها).
  - content: متن کامل همان یک پاراگراف (نه کل بخش)، در قالب گفته‌شده در FORMAT.
  - why: یک جمله‌ی کوتاه و ساده برای صاحب سایت: این تغییر برای چیست.
- پاراگرافی که لازم نیست را اصلاً در edits نیاور. هیچ پاراگرافی را حذف نکن.
- meta_title: ۴۵ تا ۶۰ کاراکتر، کلمه‌های جستجوی اصلی را طبیعی و نزدیک ابتدا داشته باشد، جذاب ولی بدون اغراق و بدون نام سایت.
- meta_description: ۱۲۰ تا ۱۶۰ کاراکتر، جمله‌ی کامل، بگوید خواننده دقیقاً چه چیزی از مقاله به دست می‌آورد.
- summary: دو یا سه جمله‌ی ساده برای صاحب سایت: چه چیزی و چرا تغییر کرد.

کیفیت تغییرها (مهم‌ترین بخش):
- اول سراغ ضعیف‌ترین جاها برو: پاراگراف کلی، مبهم، قدیمی یا بدون مثال را کامل‌تر کن. پاراگراف تازه فقط برای جوابی که اصلاً در مقاله نیست.
- هر پاراگراف تازه یا بازنویسی‌شده: جمله‌ی اول مستقیم جواب بدهد، بعد دلیل یا یک مثال ملموس از زندگی خواننده، بعد کاری که خواننده باید بکند.
- تغییر باید با پاراگراف قبل و بعدش طبیعی وصل شود؛ حرفی که جای دیگری از مقاله آمده را تکرار نکن.
- ساختار موجود مقاله را دنبال کن: اگر سؤال‌ها با تیتر کوچک و یک جواب کوتاه نوشته شده‌اند، سؤال تازه هم همین شکل باشد؛ اگر نکته‌ها فهرست‌اند، نکته‌ی تازه هم آیتم فهرست باشد.
- تغییر نمایشی نکن: عوض کردن چند کلمه بدون اینکه متن واقعاً بهتر یا کامل‌تر شود ممنوع است.
- قبل از خروجی، هر تغییر را دوباره بخوان: آیا یک خواننده‌ی معمولی از آن چیز تازه و مفیدی می‌فهمد و آیا متن روان و انسانی است؟ اگر نه، کنارش بگذار.

قوانین:
- موضوع مقاله عوض نشود. هیچ آمار، تاریخ رویداد، قیمت، مطالعه، نقل‌قول، نظر کاربر یا تجربه‌ی شخصی ساختگی ننویس. اگر چیزی ممکن است قدیمی شده باشد و واقعیت فعلی را نمی‌دانی، کلی بنویس، نه حدسی.
- هر جستجو در کل تغییرها حداکثر دو بار بیاید؛ کلمه‌ی کلیدی را به‌زور در متن نچپان.
- همان لحن، نوع خطاب و سبک قالب‌بندی متن فعلی.
- این عبارت‌ها را ننویس: {CLICHES}

FORMAT:
{FORMAT_RULE}

{PLAIN_LANGUAGE}
PROMPT;

    protected const FORMAT_RULES = [
        RefreshBlocks::MARKDOWN => 'Markdown ساده. تیتر تازه فقط ### (H3). در replace هر لینک [متن](LINK_n) پاراگراف باید بماند (متن لینک را می‌شود کمی تغییر داد، LINK_n را نه). در پاراگراف تازه LINK_n نگذار. لینک داخلی تازه فقط با [[Ln|متن لینک]].',
        RefreshBlocks::HTML => 'HTML تمیز فقط با تگ‌های p، h3، h4، ul، ol، li، strong، em، br، blockquote. هر {{KEEP_n}} یک تصویر، ویدیو، جدول، کد، شورت‌کد، نشانه‌ی ویرایشگر یا تیتر H2 است: در replace همه را عیناً و به همان ترتیب نگه دار. {{A_n}} شروع یک لینک است که با </a> تمام می‌شود: کلمه‌های بینشان را می‌شود عوض کرد، ولی هر دو بمانند. در پاراگراف تازه هیچ {{...}} نگذار. هرگز تگ a ننویس؛ لینک داخلی تازه فقط با [[Ln|متن لینک]]. استایل، اسکریپت، تصویر یا تگ دیگری اضافه نکن.',
    ];

    protected ?int $refreshId = null;

    protected string $language = 'en';

    public function __construct(
        protected ArticleWriterService $writer,
        protected SearchConsoleInsightsService $insights,
        protected SubscriptionService $subscriptions,
        protected TokenUsageService $tokenUsage,
    ) {}

    // ===============================================================
    //  شروع، تخمین هزینه
    // ===============================================================

    /**
     * @param  array{reason?:string, potential_clicks?:int}|null  $candidate  خروجی refreshCandidates (اگر از آنجا آمده)
     */
    public function start(Article $article, string $trigger = ArticleRefresh::TRIGGER_MANUAL, ?array $candidate = null): ArticleRefresh
    {
        $site = $article->site;
        $user = $site?->user;

        if (! $site || ! $user) {
            throw new RuntimeException('The site or user for this article was not found.');
        }

        $url = SearchConsoleInsightsService::publicUrl($article);

        if ($article->publish_status !== 'published' || ! $url || ! ($article->wp_post_id || $article->shopify_article_id)) {
            throw new RuntimeException('Only articles published on the site can be updated.');
        }

        if (ArticleRefresh::where('article_id', $article->id)->whereIn('status', ArticleRefresh::OPEN_STATUSES)->exists()) {
            throw new RuntimeException('An update for this article is already in progress.');
        }

        if (! $this->subscriptions->hasAvailableCredit($user)) {
            throw new InsufficientCreditException('You don\'t have enough credit to update the article. Please renew your plan.');
        }

        $hash = PageUrl::hash($url);
        $queries = $hash ? $this->insights->queriesForPage($site, $hash, self::MAX_QUERIES) : collect();

        if ($queries->isEmpty()) {
            throw new RuntimeException('There\'s no Search Console data for this article yet. Try again a few days after publishing.');
        }

        $metrics = $this->insights->pageMetrics($site)->get($hash) ?? [];
        $reason = $candidate['reason']
            ?? ($this->insights->refreshReason($metrics + ['previous_clicks' => null, 'clicks' => 0, 'impressions' => 0, 'position' => null])['reason'] ?? ArticleRefresh::REASON_MANUAL);

        $refresh = ArticleRefresh::create([
            'site_id' => $site->id,
            'source' => ArticleRefresh::SOURCE_ARTICLE,
            'article_id' => $article->id,
            'status' => ArticleRefresh::STATUS_QUEUED,
            'trigger' => $trigger,
            'reason' => $reason,
            'metrics' => $metrics + ['potential_clicks' => $candidate['potential_clicks'] ?? null],
            'queries' => $queries->all(),
            'before' => $this->snapshot($article),
        ]);

        GenerateArticleRefreshJob::dispatch($refresh->id)->onConnection($trigger === ArticleRefresh::TRIGGER_AGENT ? 'redis-background' : null);

        return $refresh;
    }

    /** @return array{min:int, max:int} */
    public function estimate(Article $article): array
    {
        return $this->estimateForChars(
            $this->textModelForUser($article->site->user),
            (int) $article->sections()->get(['content'])->sum(fn ($s) => mb_strlen((string) $s->content)),
        );
    }

    /**
     * کریدیت تقریبی یک به‌روزرسانی: یک فراخوانی با کل مقاله به‌عنوان ورودی و فقط تغییرها به‌عنوان خروجی،
     * به‌علاوه‌ی اصلاح ارزان جمله‌ها. کمینه = تغییرهای کم، بیشینه = تغییرهای زیاد.
     *
     * @return array{min:int, max:int}
     */
    public function estimateForChars(string $textModel, int $documentChars): array
    {
        $documentTokens = (int) ceil(min($documentChars, self::MAX_DOCUMENT_CHARS) / self::CHARS_PER_TOKEN);
        $input = $this->tokens(self::EDIT_SYSTEM) + $this->tokens(PersianWritingSkill::prompt()) + $documentTokens + 900;
        // مدل‌های reasoning توکن فکر کردن هم حساب می‌کنند
        $reasoning = preg_match('/^(gpt-5|o\d)/i', $textModel) ? 800 : 0;

        $min = $this->tokenUsage->estimateCredit($textModel, $input, 600 + $reasoning);
        $max = $this->tokenUsage->estimateCredit($textModel, $input, min(4500, 400 + (int) ($documentTokens * 0.4)) + $reasoning)
            + $this->tokenUsage->estimateCredit(ChatModelResolver::FREE_API_MODEL, 1200, 500);

        return ['min' => max(1, (int) ceil($min)), 'max' => max(1, (int) ceil($max))];
    }

    /** مدل متن بر اساس پلن فعلی کاربر (نه پلنی که زمان نوشتن مقاله داشت). */
    public function textModelFor(Article $article): string
    {
        return $this->textModelForUser($article->site->user);
    }

    public function textModelForUser(User $user): string
    {
        return ChatModelResolver::resolve(ChatModelResolver::PRO_MODEL_ID, $this->subscriptions->hasActivePaidPlan($user));
    }

    /** سرویس درست برای هر به‌روزرسانی: مقاله‌ی یوتیکس یا پست قدیمی سایت. */
    public static function for(ArticleRefresh $refresh): self
    {
        return app($refresh->source === ArticleRefresh::SOURCE_WP_POST ? PastArticleRefreshService::class : self::class);
    }

    // ===============================================================
    //  ساخت نسخه‌ی پیشنهادی (داخل Job)
    // ===============================================================

    public function generate(ArticleRefresh $refresh): void
    {
        $article = $refresh->article()->with('site.user')->firstOrFail();
        $site = $article->site;
        $user = $site->user;

        $this->begin($refresh, $site, $user);
        $this->setStage($refresh, self::STAGE_READING);

        $sections = $article->sections()->get();

        if ($sections->isEmpty()) {
            throw new RuntimeException('This article has no section to update.');
        }

        $documentSections = $sections->map(function (ArticleSection $s) {
            [$masked, $urls] = $this->maskLinks((string) $s->content);
            $restore = array_combine(
                array_map(fn ($p) => "({$p})", array_keys($urls)),
                array_map(fn ($u) => "({$u})", array_values($urls)),
            ) ?: [];

            return [
                'id' => $s->id,
                'type' => $s->type,
                'heading' => $s->heading,
                'masked' => $masked,
                'restore' => fn (string $text) => strtr($text, $restore),
            ];
        })->all();

        $current = ['meta_title' => $article->meta_title, 'meta_description' => $article->meta_description];
        $context = $this->seoContext(
            $site,
            SearchConsoleInsightsService::publicUrl($article),
            (string) $article->title,
            $article->focus_keyword,
            $sections->pluck('heading')->all(),
            $sections->pluck('content')->implode("\n\n"),
            RefreshBlocks::MARKDOWN,
            $article->published_at,
            (array) $refresh->queries,
        );
        $result = $this->proposeEdits(
            $refresh,
            ['title' => $article->title, 'focus_keyword' => $article->focus_keyword] + $current,
            $documentSections,
            RefreshBlocks::MARKDOWN,
            $this->textModelForUser($user),
            $context,
        );

        $this->finish($refresh, $result, $this->cleanMeta($result['plan'], $current));

        // اتوماسیون با «انتشار مستقیم»: بدون صبر برای تأیید منتشر می‌شود (مثل مقاله‌های جدید ایجنت)
        $autoPublish = $refresh->trigger === ArticleRefresh::TRIGGER_AGENT
            && AgentSetting::where('site_id', $site->id)->value('default_publish_status') === 'publish';

        if ($autoPublish) {
            $refresh->update(['status' => ArticleRefresh::STATUS_APPLYING, 'stage' => self::STAGE_PUBLISHING, 'stage_started_at' => now()]);
            ApplyArticleRefreshJob::dispatch($refresh->id, 'apply')->onConnection('redis-background');
        }
    }

    public function markFailed(ArticleRefresh $refresh, string $message): void
    {
        $refresh->update([
            'status' => ArticleRefresh::STATUS_FAILED,
            'stage' => null,
            'error' => Str::limit($message, 1000),
            'credit_used' => $this->creditUsed($refresh),
        ]);
    }

    /** کریدیت واقعی ثبت‌شده برای این به‌روزرسانی (همه‌ی فراخوانی‌ها، حتی اگر نیمه‌کاره مانده باشد). */
    public function creditUsed(ArticleRefresh $refresh): float
    {
        return round((float) TokenUsage::where('created_at', '>=', $refresh->created_at->copy()->subMinute())
            ->where('metadata->article_refresh_id', $refresh->id)
            ->sum('credit'), 4);
    }

    /** مرحله‌ی فعلی، برای نمایش زنده به کاربر. */
    protected function setStage(ArticleRefresh $refresh, ?string $stage): void
    {
        $refresh->update(['stage' => $stage, 'stage_started_at' => $stage ? now() : null]);
    }

    protected function begin(ArticleRefresh $refresh, $site, User $user): void
    {
        $this->refreshId = $refresh->id;
        $this->language = ContentLanguage::forSite($site);
        app()->bind('current_ai_user', fn () => $user);

        if (! $this->subscriptions->hasAvailableCredit($user)) {
            throw new InsufficientCreditException('You don\'t have enough credit to update the article.');
        }

        $refresh->update(['status' => ArticleRefresh::STATUS_GENERATING, 'error' => null]);
    }

    /** @param  array{sections: array, plan: array}  $result */
    protected function finish(ArticleRefresh $refresh, array $result, array $meta): void
    {
        if ($result['sections'] === [] && $meta === []) {
            throw new RuntimeException('No useful change was found; this article doesn\'t need an update right now.');
        }

        $refresh->update([
            'status' => ArticleRefresh::STATUS_READY,
            'stage' => null,
            'stage_started_at' => null,
            'summary' => Str::limit(trim((string) ($result['plan']['summary'] ?? '')), 1000),
            'after' => ['meta' => $meta, 'sections' => array_values($result['sections']), 'links' => $result['links'] ?? []],
            'credit_used' => $this->creditUsed($refresh),
        ]);
    }

    /**
     * فراخوانی واحد AI + بررسی ایمنی و اعمال تغییرهای پاراگرافی.
     *
     * @param  array{title:string, focus_keyword:?string, meta_title:?string, meta_description:?string}  $page
     * @param  array<int, array{id:int, type:string, heading:string, masked:string, restore:callable}>  $sections
     * @param  array{today?:array, issues?:array, internal_links?:int, recommended_links?:int, candidates?:array}  $context  خروجی seoContext
     * @return array{sections: array<int, array{id:int, heading:string, content:string, instruction:string}>, plan: array, links: array}
     */
    protected function proposeEdits(ArticleRefresh $refresh, array $page, array $sections, string $format, string $model, array $context = []): array
    {
        $candidates = (array) ($context['candidates'] ?? []);

        // ۱) سند شماره‌دار: هر پاراگراف یک شناسه‌ی سراسری [B..] دارد
        $blocks = [];
        $parts = [];
        $lines = [];
        $budget = self::MAX_DOCUMENT_CHARS;

        foreach ($sections as $key => $section) {
            $parts[$key] = RefreshBlocks::split($section['masked']);
            $length = mb_strlen($section['masked']);
            $editable = $length <= self::MAX_SECTION_CHARS && $length <= $budget;
            $lines[] = "## {$section['heading']} ({$section['type']})".($editable ? '' : ' — فقط برای اطلاع از ساختار، قابل ویرایش نیست');

            if (! $editable) {
                continue;
            }

            $budget -= $length;

            foreach ($parts[$key] as $index => $part) {
                if ($index % 2 === 1 || trim($part) === '') {
                    continue;
                }

                $id = count($blocks) + 1;
                $blocks[$id] = ['section' => $key, 'part' => $index, 'text' => $part];
                $lines[] = "[B{$id}] ".trim($part);
            }
        }

        if ($blocks === []) {
            throw new RuntimeException('No section of this article can be safely updated.');
        }

        // ۲) یک فراخوانی: تغییرها + عنوان و توضیح گوگل
        $this->setStage($refresh, self::STAGE_WRITING);
        $plan = $this->chat(
            model: $model,
            system: strtr(self::EDIT_SYSTEM, [
                '{MAX}' => (string) self::MAX_EDITS,
                '{MAX_LINKS}' => (string) RefreshLinks::MAX_NEW,
                '{CLICHES}' => implode('، ', array_map(fn ($c) => "«{$c}»", PersianText::PROMPT_CLICHES)),
                '{FORMAT_RULE}' => self::FORMAT_RULES[$format],
                // مهارت کامل نوشتن (نه نسخه‌ی کوتاه): کیفیت متن تازه مهم‌تر از چند صد توکن ورودی است
                '{PLAIN_LANGUAGE}' => ContentLanguage::isPersian($this->language) ? PersianWritingSkill::prompt() : PersianText::PLAIN_LANGUAGE_RULES,
            ]),
            user: $this->editPrompt($refresh, $page, $lines, $context),
            schemaName: 'article_refresh_edits',
            schema: $this->editSchema(array_keys($blocks)),
            temperature: 0.5,
            maxTokens: 4500,
        );

        // ۳) بررسی ایمنی هر تغییر با کد؛ تغییر مشکوک کنار گذاشته می‌شود، نه کل به‌روزرسانی
        $this->setStage($refresh, self::STAGE_CHECKING);
        $edits = [];
        $usedLinks = [];

        foreach (array_slice((array) ($plan['edits'] ?? []), 0, self::MAX_EDITS) as $edit) {
            $block = $blocks[(int) ($edit['block'] ?? 0)] ?? null;
            $action = $edit['action'] ?? null;
            $content = trim((string) preg_replace('/^```(?:html|markdown)?\s*|\s*```$/i', '', trim((string) ($edit['content'] ?? ''))));

            if (! $block || ! in_array($action, [RefreshBlocks::REPLACE, RefreshBlocks::INSERT_AFTER], true)) {
                continue;
            }

            if ($action === RefreshBlocks::REPLACE && ! RefreshBlocks::isEditable($block['text'], $format)) {
                continue;
            }

            // لینک تازه‌ی نامعتبر به متن ساده برمی‌گردد؛ لینک فقط وقتی مصرف می‌شود که خود تغییر پذیرفته شود
            [$content, $newLinks] = RefreshLinks::sanitize($content, $format, $candidates, $usedLinks);
            $original = $action === RefreshBlocks::REPLACE ? $block['text'] : null;
            $problems = [
                ...RefreshBlocks::problems($original, $content, $format),
                ...$this->qualityProblems($original, $content, $format, $blocks),
            ];

            if ($problems !== []) {
                Log::warning('ai.article_refresh.edit_rejected', ['refresh_id' => $this->refreshId, 'block' => $edit['block'] ?? null, 'problems' => $problems]);

                continue;
            }

            $usedLinks = [...$usedLinks, ...$newLinks];
            $edits[] = $block + ['action' => $action, 'content' => $content, 'original' => $original, 'why' => trim((string) ($edit['why'] ?? ''))];
        }

        $edits = $this->polishEdits($edits, $format);

        // ۴) ساخت متن تازه‌ی هر بخش و بررسی دوباره‌ی کل بخش (تور ایمنی)
        $result = [];
        $links = [];
        $number = 0;
        $render = fn (string $text, bool $mark = false) => RefreshLinks::render($text, $format, $candidates, $mark);

        foreach (collect($edits)->groupBy('section') as $key => $sectionEdits) {
            $replace = [];
            $insert = [];
            // نسخه‌ی نمایشی (فقط برای پیش‌نمایش، نه انتشار): هر پاراگراف تغییرکرده با نشانه‌ای برای برجسته شدن
            $replaceDisplay = [];
            $insertDisplay = [];
            $details = [];
            $sectionLinks = [];

            foreach ($sectionEdits as $edit) {
                $n = ++$number;
                $marked = $format === RefreshBlocks::HTML
                    ? '<div data-yx-edit="'.$edit['action'].'" data-yx-n="'.$n.'">'.$edit['content'].'</div>'
                    : $edit['content'];

                if ($edit['action'] === RefreshBlocks::REPLACE) {
                    $replace[$edit['part']] = $edit['content'];
                    $replaceDisplay[$edit['part']] = $marked;
                } else {
                    $insert[$edit['part']][] = $edit['content'];
                    $insertDisplay[$edit['part']][] = $marked;
                }

                $details[] = ['n' => $n, 'action' => $edit['action'], 'why' => $edit['why'], 'before' => $edit['original'], 'after' => $edit['content']];

                foreach (RefreshLinks::markers($edit['content']) as $target => $anchor) {
                    $sectionLinks[] = ['n' => $n, 'anchor' => $anchor] + $candidates[$target];
                }
            }

            $section = $sections[$key];
            $newMasked = RefreshBlocks::rebuild($parts[$key], $replace, $insert);

            if (! $this->sectionStillSafe($section['masked'], $newMasked, $format)) {
                Log::warning('ai.article_refresh.section_rejected', ['refresh_id' => $this->refreshId, 'section' => $section['id']]);

                continue;
            }

            $restore = $section['restore'];
            $links = [...$links, ...array_map(fn (array $l) => $l + ['section' => $section['heading']], $sectionLinks)];
            $result[$key] = [
                'id' => $section['id'],
                'heading' => $section['heading'],
                'content' => $render($restore($newMasked)),
                'instruction' => $sectionEdits->pluck('why')->filter()->unique()->implode(' '),
                'display' => $format === RefreshBlocks::HTML ? $render($restore(RefreshBlocks::rebuild($parts[$key], $replaceDisplay, $insertDisplay)), true) : null,
                'edits' => array_map(fn (array $d) => [
                    'n' => $d['n'],
                    'action' => $d['action'],
                    'why' => $d['why'],
                    'before' => $d['before'] !== null ? $restore($d['before']) : null,
                    'after' => $render($restore($d['after'])),
                ], $details),
            ];
        }

        return ['sections' => $result, 'plan' => $plan, 'links' => $links];
    }

    /**
     * تغییری که عملاً همان متن قبلی است، یا پاراگراف تازه‌ای که حرف یک پاراگراف موجود را تکرار می‌کند،
     * ارزشی برای خواننده ندارد و فقط پیش‌نمایش را شلوغ می‌کند.
     *
     * @param  array<int, array{text:string}>  $blocks
     * @return array<int, string>
     */
    protected function qualityProblems(?string $original, string $content, string $format, array $blocks): array
    {
        $new = RefreshBlocks::text(RefreshLinks::strip($content), $format);

        if ($original !== null) {
            // نشاندن یک لینک داخلی مرتبط خودش تغییر واقعی است، حتی اگر بقیه‌ی جمله تقریباً همان بماند
            if (RefreshLinks::markers($content) !== []) {
                return [];
            }

            similar_text(RefreshBlocks::text($original, $format), $new, $percent);

            return $percent >= 92 ? ['no_real_change'] : [];
        }

        foreach ($blocks as $block) {
            $existing = RefreshBlocks::text($block['text'], $format);

            if (mb_strlen($existing) > 40 && mb_strlen($new) > 40) {
                similar_text($existing, $new, $percent);

                if ($percent >= 80) {
                    return ['duplicates_existing'];
                }
            }
        }

        return [];
    }

    protected function editPrompt(ArticleRefresh $refresh, array $page, array $documentLines, array $context = []): string
    {
        $today = $context['today'] ?? ContentAudit::today();
        $issues = collect($context['issues'] ?? [])->map(fn (array $i) => "- {$i['label']}: {$i['detail']}")->implode("\n");
        $kinds = ['youtix' => 'Youtix article', 'product' => 'product article', 'post' => 'site post'];
        $candidates = collect($context['candidates'] ?? [])
            ->map(fn (array $c, int $n) => "[L{$n}] {$c['title']} ({$kinds[$c['kind']]}".($c['fresh'] ? ', new' : '').')')
            ->implode("\n");

        $competitors = (array) ($context['competitors'] ?? []);
        $queryLines = collect($refresh->queries)->map(fn ($q) => sprintf(
            '- %s | impressions: %d | clicks: %d | position: %s',
            $q['query'], $q['impressions'], $q['clicks'], $q['position'] ?? '?',
        ).(SearchConsoleInsightsService::isQuestion($q['query']) ? ' | question' : '')
            .(isset($competitors[$q['query']]) ? ' | main page for this search is another page of this site: '.$competitors[$q['query']] : ''))->implode("\n");
        $metrics = (array) $refresh->metrics;

        return implode("\n\n", [
            "TODAY: {$today['jalali']} ({$today['gregorian']}). Current year: {$today['jalali_year']} ({$today['gregorian_year']}).",
            "REASON: {$refresh->reason}",
            "SEO CHECK (automatic):\n".($issues !== '' ? $issues : '- no problems found'),
            isset($context['internal_links'])
                ? "Internal links in this article now: {$context['internal_links']} (recommended for its length: at least {$context['recommended_links']})."
                : 'Internal links in this article now: unknown.',
            $candidates !== ''
                ? "INTERNAL LINK CANDIDATES (pages of this same site; link with [[Ln|anchor]]):\n{$candidates}"
                : 'INTERNAL LINK CANDIDATES: none. Do not add new links.',
            "Article title: {$page['title']}\nFocus keyword: ".($page['focus_keyword'] ?: '-'),
            'Current Google title: '.($page['meta_title'] ?: $page['title'])."\nCurrent Google description: ".($page['meta_description'] ?: '-'),
            $queryLines !== ''
                ? sprintf('Page in last 28 days: clicks %d (before: %s), impressions %d, average position %s',
                    $metrics['clicks'] ?? 0, $metrics['previous_clicks'] ?? '?', $metrics['impressions'] ?? 0, $metrics['position'] ?? '?')
                    ."\n\nSearches this page appears for (Search Console):\n{$queryLines}"
                : 'No Search Console data for this page yet.',
            "ARTICLE (numbered paragraphs):\n".implode("\n", $documentLines),
        ]);
    }

    /** @param  array<int, int>  $blockIds */
    protected function editSchema(array $blockIds): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'meta_title' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'edits' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_EDITS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'block' => ['type' => 'integer', 'enum' => array_values($blockIds)],
                            'action' => ['type' => 'string', 'enum' => [RefreshBlocks::REPLACE, RefreshBlocks::INSERT_AFTER]],
                            'content' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                        ],
                        'required' => ['block', 'action', 'content', 'why'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'meta_title', 'meta_description', 'edits'],
            'additionalProperties' => false,
        ];
    }

    /**
     * اصلاح ارزان جمله‌های فارسیِ فقط متن‌های تازه (نه کل مقاله) با یک فراخوانی؛ اگر خروجی اصلاح
     * دوباره از بررسی ایمنی رد نشود جایگزین می‌شود، وگرنه همان متن قبلی می‌ماند.
     */
    protected function polishEdits(array $edits, string $format): array
    {
        if ($edits === [] || ! ContentLanguage::isPersian($this->language)) {
            return $edits;
        }

        $combined = implode("\n".self::POLISH_SEPARATOR."\n", array_column($edits, 'content'));
        $issues = [
            ...PersianText::findPhrases($combined, PersianText::AI_CLICHES),
            ...PersianWritingSkill::issues($combined),
            ...PersianText::latinWordIssues(strip_tags($combined)),
        ];

        if ($issues === []) {
            return $edits;
        }

        $polished = explode("\n".self::POLISH_SEPARATOR."\n", $this->polishLines(
            $combined,
            $issues,
            fn (string $line) => trim($line) !== self::POLISH_SEPARATOR && (
                PersianText::findPhrases($line, PersianText::AI_CLICHES) !== []
                || PersianWritingSkill::lineHasIssue($line)
                || PersianText::latinWords(strip_tags($line)) !== []
            ),
        ));

        if (count($polished) !== count($edits)) {
            return $edits;
        }

        foreach ($edits as $i => $edit) {
            $candidate = trim($polished[$i]);

            if ($candidate !== '' && RefreshLinks::markers($candidate) === RefreshLinks::markers($edit['content'])
                && RefreshBlocks::problems($edit['original'], $candidate, $format) === []) {
                $edits[$i]['content'] = $candidate;
            }
        }

        return $edits;
    }

    /** بعد از ساخت متن کامل بخش: بلوک‌های قفل‌شده و لینک‌ها همان‌هایی‌اند که بودند. */
    protected function sectionStillSafe(string $before, string $after, string $format): bool
    {
        if ($format === RefreshBlocks::HTML) {
            // فقط ایراد تازه مهم است؛ HTML قدیمی سایت ممکن است از قبل تگ جفت‌نشده داشته باشد
            return array_diff(PostHtml::problems($before, $after), PostHtml::problems($before, $before)) === [];
        }

        $old = RefreshBlocks::tokens($before, $format);
        $new = RefreshBlocks::tokens($after, $format);
        sort($old);
        sort($new);

        return $old === $new;
    }

    // ===============================================================
    //  انتشار و برگرداندن (داخل Job)
    // ===============================================================

    /** نسخه‌ی پیشنهادی را روی مقاله می‌نشاند و منتشر می‌کند؛ اگر انتشار شکست بخورد، مقاله به حالت قبل برمی‌گردد. */
    public function apply(ArticleRefresh $refresh): void
    {
        $this->switchVersion($refresh, from: 'before', to: 'after', successStatus: ArticleRefresh::STATUS_APPLIED, failStatus: ArticleRefresh::STATUS_READY);
    }

    public function revert(ArticleRefresh $refresh): void
    {
        $this->switchVersion($refresh, from: 'after', to: 'before', successStatus: ArticleRefresh::STATUS_REVERTED, failStatus: ArticleRefresh::STATUS_APPLIED);
    }

    protected function switchVersion(ArticleRefresh $refresh, string $from, string $to, string $successStatus, string $failStatus): void
    {
        $article = $refresh->article()->with('site')->firstOrFail();
        $sectionIds = array_column($refresh->after['sections'] ?? [], 'id');
        $metaKeys = array_keys($refresh->after['meta'] ?? []);

        // اگر کاربر بعد از ساخت پیشنهاد، مقاله را دستی ویرایش کرده، روی ویرایشش نوشته نمی‌شود
        if (! $this->matchesVersion($article, $this->version($refresh, $from), $sectionIds, $metaKeys)) {
            $refresh->update([
                'status' => $failStatus,
                'stage' => null,
                'error' => 'The article was edited after this suggestion was made. Nothing was changed so your edits aren\'t lost.',
            ]);

            return;
        }

        $this->writeVersion($article, $this->version($refresh, $to), $sectionIds, $metaKeys);
        $fresh = $article->fresh();
        $result = app(ArticlePublisherResolver::class)->for($fresh)->update($fresh);

        if ($result['ok'] ?? false) {
            $refresh->update([
                'status' => $successStatus,
                'stage' => null,
                'error' => null,
                'applied_at' => $successStatus === ArticleRefresh::STATUS_APPLIED ? now() : $refresh->applied_at,
            ]);

            return;
        }

        // سایت به‌روز نشد → مقاله‌ی داخلی هم به نسخه‌ی قبلی برمی‌گردد تا با سایت یکی بماند
        $this->writeVersion($article, $this->version($refresh, $from), $sectionIds, $metaKeys);
        $refresh->update([
            'status' => $failStatus,
            'stage' => null,
            'error' => 'Publishing to the site failed: '.Str::limit((string) ($result['message'] ?? 'Unknown error'), 500),
        ]);
    }

    /** @return array{meta: array<string, ?string>, sections: array<int, string>} محتوای هر بخش بر اساس id */
    protected function version(ArticleRefresh $refresh, string $which): array
    {
        $data = $which === 'after' ? $refresh->after : $refresh->before;

        return [
            'meta' => (array) ($data['meta'] ?? []),
            'sections' => collect($data['sections'] ?? [])->mapWithKeys(fn ($s) => [(int) $s['id'] => (string) $s['content']])->all(),
        ];
    }

    protected function matchesVersion(Article $article, array $version, array $sectionIds, array $metaKeys): bool
    {
        $current = ArticleSection::where('article_id', $article->id)->whereIn('id', $sectionIds)->pluck('content', 'id');

        foreach ($sectionIds as $id) {
            if (! $current->has($id) || (string) $current[$id] !== ($version['sections'][$id] ?? null)) {
                return false;
            }
        }

        foreach ($metaKeys as $key) {
            if ((string) $article->{$key} !== (string) ($version['meta'][$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    protected function writeVersion(Article $article, array $version, array $sectionIds, array $metaKeys): void
    {
        DB::transaction(function () use ($article, $version, $sectionIds, $metaKeys) {
            foreach ($sectionIds as $id) {
                ArticleSection::where('article_id', $article->id)->whereKey($id)->update(['content' => $version['sections'][$id]]);
            }

            if ($metaKeys !== []) {
                $article->update(array_intersect_key($version['meta'], array_flip($metaKeys)));
            }
        });
    }

    /** @return array{meta: array<string, ?string>, sections: array<int, array>} */
    protected function snapshot(Article $article): array
    {
        return [
            'meta' => [
                'meta_title' => $article->meta_title,
                'meta_description' => $article->meta_description,
            ],
            'sections' => $article->sections()->get(['id', 'position', 'type', 'heading', 'content'])
                ->map(fn (ArticleSection $s) => $s->only(['id', 'position', 'type', 'heading', 'content']))
                ->all(),
        ];
    }

    // ===============================================================
    //  ابزارها
    // ===============================================================

    /**
     * تاریخ امروز، ایرادهای سئوی متن و صفحه‌های مرتبطِ همین سایت که می‌شود به آن‌ها لینک داد.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, array{query?:string}>  $queries  جستجوهای سرچ کنسول این صفحه
     * @param  array<string, mixed>|null  $audit  اگر از قبل حساب شده (پست قدیمی)
     */
    protected function seoContext($site, ?string $selfUrl, string $title, ?string $focus, array $headings, string $content, string $format, ?CarbonInterface $updatedAt, array $queries, ?array $audit = null): array
    {
        $audit ??= ContentAudit::audit($content, $format, $site->domain);
        $topic = implode(' ', [$title, (string) $focus, ...$headings, ...array_slice(array_column($queries, 'query'), 0, 5)]);

        return [
            'today' => ContentAudit::today(),
            'issues' => ContentAudit::issues($audit, $title, $updatedAt),
            'internal_links' => (int) $audit['internal_links'],
            'recommended_links' => ContentAudit::recommendedLinks((int) $audit['words']),
            'candidates' => app(InternalLinkCandidates::class)->forPage($site, $selfUrl, $topic, (array) ($audit['internal_urls'] ?? [])),
            // جستجوهای این صفحه که صفحه‌ی دیگری از سایت صفحه‌ی اصلی‌شان است
            'competitors' => $this->insights->competitorsForPage($site, PageUrl::hash($selfUrl)),
        ];
    }

    /**
     * آدرس لینک‌ها قبل از AI با LINK_n عوض می‌شود تا نه AI و نه بازنویسی خط‌ها نتوانند آن را خراب کنند.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function maskLinks(string $content): array
    {
        $urls = [];

        $masked = (string) preg_replace_callback('/\]\(([^)\s]+)\)/u', function ($m) use (&$urls) {
            $placeholder = array_search($m[1], $urls, true) ?: 'LINK_'.(count($urls) + 1);
            $urls[$placeholder] = $m[1];

            return "]({$placeholder})";
        }, $content);

        return [$masked, $urls];
    }

    /**
     * فقط عنوان/توضیحی که طولش معقول است و واقعاً فرق کرده پیشنهاد می‌شود.
     *
     * @param  array{meta_title:?string, meta_description:?string}  $current
     */
    protected function cleanMeta(array $plan, array $current): array
    {
        $meta = [];
        $title = trim(str_replace(['«', '»'], '', (string) ($plan['meta_title'] ?? '')));
        $description = trim((string) ($plan['meta_description'] ?? ''));

        if (mb_strlen($title) >= 25 && mb_strlen($title) <= 70 && $title !== (string) ($current['meta_title'] ?? '')) {
            $meta['meta_title'] = ContentLanguage::isPersian($this->language) ? PersianText::normalize($title) : $title;
        }

        if (mb_strlen($description) >= 80 && mb_strlen($description) <= 180 && $description !== (string) ($current['meta_description'] ?? '')) {
            $meta['meta_description'] = ContentLanguage::isPersian($this->language) ? PersianText::normalize($description) : $description;
        }

        return $meta;
    }

    /** امضای سازگار با PolishesLines؛ مصرف با article_refresh_id ثبت می‌شود. */
    protected function chat(
        string $model,
        string $system,
        string $user,
        string $schemaName,
        array $schema,
        float $temperature = 0.7,
        int $timeout = 120,
        int $maxTokens = 4000,
    ): array {
        return $this->writer->chat(
            model: $model,
            system: $system,
            user: $user,
            schemaName: $schemaName,
            schema: $schema,
            temperature: $temperature,
            timeout: $timeout,
            maxTokens: $maxTokens,
            language: $this->language,
            usageMetadata: ['article_refresh_id' => $this->refreshId],
        );
    }

    protected function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
