<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * اتوماسیون شماره‌ی دسته را با max(batch)+1 می‌گرفت و درخواست دستی با شمارنده‌ی سایت؛ گاهی هر دو یک شماره می‌گرفتند
 * و گفتگوی کاربر ایده‌های اتوماسیون (حتی با مقاله‌ی تولیدشده) را کنار ایده‌های تازه نشان می‌داد.
 * ایده‌های اتوماسیونِ هر دسته‌ی قاطی به یک شماره‌ی تازه می‌روند؛ پیام‌های گفتگو فقط شماره‌ی دسته‌ی کاربر را دارند، پس دست نمی‌خورند.
 * شمارنده‌ی سایت عوض نمی‌شود (سهمیه‌ی پلن رایگان همان می‌ماند)؛ Site::reserveIdeaBatch از max هر دو جلو می‌رود.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mixed = DB::table('article_ideas')
            ->select('website_id', 'batch')
            ->groupBy('website_id', 'batch')
            ->havingRaw("SUM(CASE WHEN source = 'agent' THEN 1 ELSE 0 END) > 0")
            ->havingRaw("SUM(CASE WHEN source = 'agent' THEN 0 ELSE 1 END) > 0")
            ->get();

        foreach ($mixed as $row) {
            $next = max(
                (int) DB::table('article_ideas')->where('website_id', $row->website_id)->max('batch'),
                (int) DB::table('sites')->where('id', $row->website_id)->value('idea_generation_batch'),
            ) + 1;

            DB::table('article_ideas')
                ->where('website_id', $row->website_id)
                ->where('batch', $row->batch)
                ->where('source', 'agent')
                ->update(['batch' => $next]);
        }
    }

    public function down(): void
    {
        // جداسازی برگشت داده نمی‌شود؛ قاطی بودن خودش همان ایراد بود
    }
};
