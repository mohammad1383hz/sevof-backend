<?php
//flow trend
namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteKeywordTrend;
use App\Services\AI\KeywordSelectionService;
use App\Services\Trends\GoogleTrendsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeSiteKeywordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public Site $site) {}

    public function handle(GoogleTrendsService $trends, KeywordSelectionService $aiSelector): void
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

        $results = [];

        foreach ($site->keywords as $index => $keyword) {

            // ۵ ثانیه delay بین هر درخواست — قبل از اولی نه
            if ($index > 0) {
                Log::info("AnalyzeSiteKeywordsJob: صبر ۵ ثانیه قبل از '{$keyword}' (سایت {$site->id})...");
                sleep(5);
            }

            $record = SiteKeywordTrend::updateOrCreate(
                ['site_id' => $site->id, 'keyword' => $keyword],
                ['geo' => 'IR', 'timeframe' => 'now 7-d', 'status' => 'pending']
            );

            try {
                $data = $trends->single($keyword, 'IR', 'now 7-d');

                $record->update([
                    'top_queries'     => $data['top']     ?? [],
                    'rising_queries'  => $data['rising']  ?? [],
                    'status'          => 'fetched',
                    'fetched_at'      => now(),
                    'error_message'   => null,
                ]);

                $results[] = [
                    'keyword' => $keyword,
                    'top'     => $data['top']     ?? [],
                    'rising'  => $data['rising']  ?? [],
                ];

                Log::info("AnalyzeSiteKeywordsJob: ترند '{$keyword}' با موفقیت دریافت شد (سایت {$site->id}).");

            } catch (Throwable $e) {
                Log::error("AnalyzeSiteKeywordsJob: خطا برای '{$keyword}' - سایت {$site->id}: " . $e->getMessage());

                $record->update([
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        if (empty($results)) {
            $site->update([
                'keyword_analysis_status' => Site::KEYWORD_STATUS_FAILED,
                'keyword_analysis_error'  => 'هیچ داده‌ای از گوگل ترندز دریافت نشد.',
            ]);
            return;
        }

        try {
            $selection = $aiSelector->select($site, $results);

            $site->update([
                'final_keyword'           => $selection['final_keyword'],
                'final_title'             => $selection['title'],
                'keyword_analysis_status' => Site::KEYWORD_STATUS_DONE,
                'keyword_analysis_error'  => null,
            ]);
        } catch (Throwable $e) {
            Log::error("AnalyzeSiteKeywordsJob: انتخاب کلمه کلیدی نهایی برای سایت {$site->id} شکست خورد: " . $e->getMessage());

            $site->update([
                'keyword_analysis_status' => Site::KEYWORD_STATUS_FAILED,
                'keyword_analysis_error'  => $e->getMessage(),
            ]);
        }
    }
}