<?php
// app/Console/Commands/RecalculateArticleWordCounts.php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

class RecalculateArticleWordCounts extends Command
{
    protected $signature = 'articles:recalculate-word-count
                            {--chunk=200 : تعداد رکورد در هر batch}
                            {--dry-run : فقط نمایش بده، آپدیت نکن}';

    protected $description = 'محاسبه‌ی مجدد و درست word_count برای تمام مقاله‌ها (پشتیبانی کامل از فارسی)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        $total = 0;
        $updated = 0;

        Article::query()
            ->whereNotNull('content')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($articles) use (&$total, &$updated, $dryRun) {
                foreach ($articles as $article) {
                    $total++;

                    $plainText = trim(strip_tags($article->content));
                    $newCount = $plainText === '' ? 0 : count(preg_split('/\s+/u', $plainText));

                    if ($newCount === $article->word_count) {
                        continue; // چیزی تغییر نکرده، رد شو
                    }

                    $this->line("Article #{$article->id}: {$article->word_count} -> {$newCount}");

                    if (! $dryRun) {
                        $article->newQuery()
                            ->whereKey($article->id)
                            ->update(['word_count' => $newCount]);
                    }

                    $updated++;
                }
            });

        $this->info("پردازش شد: {$total} مقاله | آپدیت شد: {$updated} مقاله" . ($dryRun ? ' (dry-run — چیزی ذخیره نشد)' : ''));

        return self::SUCCESS;
    }
}
