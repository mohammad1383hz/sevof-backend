<?php

namespace App\Jobs;
//flow2
use App\Models\Site;
use App\Models\SiteKeywordSuggestion;
use App\Models\SiteUsedKeyword;
use App\Services\AI\KeywordSelectionSuggestionsService;
use App\Services\Autocomplete\JustSerpApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Support\ContentLanguage;

class AnalyzeSiteSuggestionsKeywordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    /** فاصله بین هر درخواست به API برای جلوگیری از rate limit */
    protected int $delayBetweenRequests = 2;

    public function __construct(public Site $site) {}

    public function handle(JustSerpApiService $autocomplete, KeywordSelectionSuggestionsService $aiSelector): void
    {
        app()->bind('current_ai_user', fn () => $this->site->user);

        $site = $this->site;

        if (empty($site->keywords)) {
            $site->update([
                'keyword_analysis_status' => Site::KEYWORD_STATUS_FAILED,
                'keyword_analysis_error'  => 'کلمات کلیدی برای این سایت یافت نشد.',
            ]);
            return;
        }

        $site->update(['keyword_analysis_status' => Site::KEYWORD_STATUS_PROCESSING]);

        $results = $this->fetchSuggestionsForAllKeywords($site, $autocomplete);

        if (empty($results)) {
            $site->update([
                'keyword_analysis_status' => Site::KEYWORD_STATUS_FAILED,
                'keyword_analysis_error'  => 'هیچ پیشنهادی از Google Autocomplete دریافت نشد.',
            ]);
            return;
        }

        $this->selectFinalKeyword($site, $aiSelector, $results);
    }

    /**
     * برای تک‌تک keyword های سایت، Autocomplete صدا می‌زنه و در DB ذخیره می‌کنه.
     *
     * @return array  فقط نتایج موفق، برای پاس دادن به AI
     */
    protected function fetchSuggestionsForAllKeywords(Site $site, JustSerpApiService $autocomplete): array
    {
        $results = [];

        foreach ($site->keywords as $index => $keyword) {

            if ($index > 0) {
                sleep($this->delayBetweenRequests);
            }

            $record = SiteKeywordSuggestion::updateOrCreate(
                ['site_id' => $site->id, 'keyword' => $keyword],
                ['status' => 'pending', 'error_message' => null],
            );

            $response = $autocomplete->googleAutocomplete($keyword, ContentLanguage::forSite($site));

            if (! $response['success']) {
                $record->update([
                    'status'        => 'failed',
                    'error_message' => $response['message'] ?? 'Unknown error',
                ]);

                Log::error("AnalyzeSiteKeywordsJob: خطا برای '{$keyword}' - سایت {$site->id}: ".($response['message'] ?? ''));
                continue;
            }

            $suggestions       = $response['data']['suggestions'] ?? [];
            $verbatimRelevance = $response['data']['verbatim_relevance'] ?? null;

            $record->update([
                'suggestions'        => $suggestions,
                'verbatim_relevance' => $verbatimRelevance,
                'status'             => 'fetched',
                'fetched_at'         => now(),
            ]);

            $results[] = [
                'keyword'     => $keyword,
                'suggestions' => $suggestions,
            ];

            Log::info("AnalyzeSiteKeywordsJob: پیشنهادات '{$keyword}' با موفقیت دریافت شد (سایت {$site->id}).");
        }

        return $results;
    }

    /**
     * نتایج جمع‌آوری‌شده رو به AI می‌ده و کلمه کلیدی نهایی رو روی سایت ذخیره می‌کنه.
     */
protected function selectFinalKeyword(Site $site, KeywordSelectionSuggestionsService $aiSelector, array $results): void
{
    try {
        // کلمات/موضوعاتی که قبلاً برای این سایت انتخاب و مصرف شدن
        $usedKeywords = $site->usedKeywords()
            ->pluck('title', 'keyword')
            ->toArray(); // ['keyword' => 'title', ...]

        // این آرگومان سوم رو باید داخل KeywordSelectionSuggestionsService::select() هم اضافه کنی
        // تا توی پرامپت AI بگه: "این موضوعات قبلاً استفاده شدن، دوباره انتخابشون نکن"
        $selection = $aiSelector->select($site, $results, $usedKeywords);

        // اگر AI به هر دلیلی چیزی تکراری برگردوند، جلوش رو بگیر
        if (isset($usedKeywords[$selection['final_keyword']])) {
            throw new \RuntimeException("The keyword '{$selection['final_keyword']}' has already been used for this site.");
        }

        $site->update([
            'final_keyword'           => $selection['final_keyword'],
            'final_title'             => $selection['title'],
            'keyword_analysis_status' => Site::KEYWORD_STATUS_DONE,
            'keyword_analysis_error'  => null,
        ]);

        // ذخیره در تاریخچه تا دفعات بعد تکراری انتخاب نشه
        SiteUsedKeyword::updateOrCreate(
            ['site_id' => $site->id, 'keyword' => $selection['final_keyword']],
            ['title' => $selection['title']],
        );

    } catch (Throwable $e) {
        Log::error("AnalyzeSiteKeywordsJob: انتخاب کلمه کلیدی نهایی برای سایت {$site->id} شکست خورد: ".$e->getMessage());

        $site->update([
            'keyword_analysis_status' => Site::KEYWORD_STATUS_FAILED,
            'keyword_analysis_error'  => $e->getMessage(),
        ]);
    }
}
}