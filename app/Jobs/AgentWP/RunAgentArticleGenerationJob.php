<?php

namespace App\Jobs\AgentWP;

use App\Jobs\GenerateArticleIdeasJob;
use App\Jobs\StartArticleGenerationJob;
use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\ArticleIdea;
use App\Models\Site;
use App\Services\AI\ChatModelResolver;
use App\Services\Image\ImageModelResolver;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class RunAgentArticleGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Site $site,
        public AgentSetting $setting,
        public bool $isRetryAfterIdeaGeneration = false,
    ) {}

    public function handle(SubscriptionService $subscriptionService): void
    {
        // ⬅️ گارد امنیتی جدید: این جاب ممکنه قبلاً توی صف مونده باشه، یا از طریق
        // زنجیره‌ی خودتکرار (Bus::chain -> new self(...)) دیسپچ شده باشه.
        // چون مدل‌ها موقع dequeue شدن دوباره از دیتابیس خونده می‌شن (SerializesModels)،
        // اینجا refresh می‌کنیم و وضعیتِ *فعلیِ* agent_enabled / articles_enabled رو
        // چک می‌کنیم. بدون این چک، حتی بعد از خاموش کردن Agent توسط کاربر،
        // جاب‌های قبلاً دیسپچ‌شده یا زنجیره‌شده همچنان مقاله تولید می‌کنن.
        $this->site->refresh();
        $this->setting->refresh();

        if (! $this->setting->isActive()) {
            return;
        }

        $user = $this->site->user;

        if (! $subscriptionService->hasAvailableCredit($user)) {
            $this->disableAgentForUser();

            return;
        }

        // ایده‌های ردشده یا «برای اتوماسیون ممنوع» توسط کاربر هرگز انتخاب نمی‌شوند
        $idea = ArticleIdea::where('website_id', $this->site->id)
            ->availableForAutopilot()
            ->inRandomOrder()
            ->first();

        if (! $idea) {
            if ($this->isRetryAfterIdeaGeneration) {
                return;
            }

            $nextBatch = $this->site->reserveIdeaBatch();

            Bus::chain([
                new GenerateArticleIdeasJob($this->site, $nextBatch, source: 'agent'),
                new self($this->site, $this->setting, true),
            ])->onConnection($this->connection)->dispatch();

            return;
        }

        $existing = Article::where('article_idea_id', $idea->id)
            ->whereNotIn('status', [Article::STATUS_FAILED])
            ->first();

        if ($existing) {
            return;
        }

        $isProUser = $subscriptionService->hasActivePaidPlan($user);
        $resolvedApiModel = ChatModelResolver::resolve($this->setting->content_model, $isProUser);

        if ($this->setting->image_generation_enabled) {
            [$resolvedImageModel, $resolvedImageCount] = ImageModelResolver::resolve(
                $this->setting->image_model,
                $this->setting->images_per_article,
                $isProUser,
            );

            $imageUserPrompt = $this->normalizeUserPrompt($this->setting->image_user_prompt, 500);
        } else {
            $resolvedImageModel = null;
            $resolvedImageCount = 0;
            $imageUserPrompt = null;
        }

        // ⬅️ prompt دلخواه مقاله و بازه‌ی تعداد بخش‌ها، از تنظیمات agent خونده می‌شه
        $articleUserPrompt = $this->normalizeUserPrompt($this->setting->article_user_prompt, 2000);
        $minSections = $this->setting->min_sections;
        $maxSections = $this->setting->max_sections;

        $article = Article::create([
            'article_idea_id' => $idea->id,
            'website_id' => $this->site->id,
            'title' => $idea->title,
            'meta_description' => $idea->meta_description,
            'status' => Article::STATUS_PENDING,
            'selected_model' => $resolvedApiModel,
            'image_model' => $resolvedImageModel,
            'image_count' => $resolvedImageCount,
            'image_user_prompt' => $imageUserPrompt,
            'article_user_prompt' => $articleUserPrompt, // ⬅️ جدید
            'min_sections' => $minSections,               // ⬅️ جدید
            'max_sections' => $maxSections,                // ⬅️ جدید
            'source' => Article::SOURCE_AGENT,
        ]);

        StartArticleGenerationJob::dispatch($article)->onConnection($this->connection);
    }

    /**
     * وقتی کردیت/اشتراک کافی نیست، Agent رو برای این سایت خاموش کن
     * تا دوباره توی run بعدی autopilot تلاش نکنه و جاب اضافه دیسپچ نشه.
     */
    protected function disableAgentForUser(): void
    {
        if (! $this->setting->agent_enabled) {
            return; // از قبل خاموشه، کاری لازم نیست
        }

        $this->setting->update([
            'agent_enabled' => false,
            'next_articles_run_at' => null, // اختیاری: ریست زمان‌بندی
        ]);

        // اینجا نوتیفیکیشن به کاربر بده که Agent به خاطر نبود کردیت/اشتراک خاموش شد
        // $this->site->user->notify(new \App\Notifications\AgentAutoDisabledNotification($this->site));
    }

    /**
     * پاک‌سازی مشترک prompt متنی ورودی نامطمئن (تگ/خط‌جدید/فاصله‌ی اضافه حذف می‌شود).
     */
    protected function normalizeUserPrompt(?string $value, int $maxLength): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        $value = str_replace(['<', '>'], '', $value);
        $value = mb_substr($value, 0, $maxLength);

        return $value !== '' ? $value : null;
    }
}
