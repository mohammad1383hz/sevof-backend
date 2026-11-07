<?php

namespace Tests\Unit;

use App\Services\AI\ArticleIdeaService;
use Tests\TestCase;

class ArticleIdeaDuplicateTest extends TestCase
{
    private function similarity(string $a, string $b): float
    {
        $service = $this->app->make(ArticleIdeaService::class);
        $tokens = fn (string $t) => (fn () => $this->titleTokens($t))->call($service);

        return (fn () => $this->titleSimilarity($tokens($a), $tokens($b)))->call($service);
    }

    public function test_reworded_same_topic_is_duplicate(): void
    {
        $this->assertGreaterThanOrEqual(0.7, $this->similarity('بهترین زمان کاشت گوجه فرنگی', 'زمان کاشت گوجه فرنگی: راهنمای کامل'));
        // ی/ک عربی و نیم‌فاصله نباید تکرار را پنهان کنند
        $this->assertGreaterThanOrEqual(0.7, $this->similarity('راهنمای خرید کفش‌ ورزشی', 'خرید كفش ورزشي'));
    }

    public function test_different_topic_on_same_entity_is_kept(): void
    {
        $this->assertLessThan(0.7, $this->similarity('هزینه کاشت گوجه', 'زمان کاشت گوجه'));
        $this->assertLessThan(0.7, $this->similarity('اشتباهات رایج در آبیاری گوجه', 'زمان کاشت گوجه فرنگی'));
    }
}
