<?php

namespace Tests\Unit;

use App\Services\Research\EntityKeywordSuggestionService;
use Tests\TestCase;

class EntityKeywordLanguageTest extends TestCase
{
    private function keep(array $keywords, string $language): array
    {
        $service = $this->app->make(EntityKeywordSuggestionService::class);

        return array_column((fn () => $this->keepSiteLanguage($keywords, $language))->call($service), 'base_keyword');
    }

    public function test_keywords_follow_site_language(): void
    {
        $keywords = [
            ['base_keyword' => 'آموزش تولید محتوا با هوش مصنوعی'],
            ['base_keyword' => 'ai content generator'],
            ['base_keyword' => 'تولید محتوا با chatgpt'],
            ['base_keyword' => ''],
        ];

        $this->assertSame(['آموزش تولید محتوا با هوش مصنوعی', 'تولید محتوا با chatgpt'], $this->keep($keywords, 'fa'));
        $this->assertSame(['ai content generator'], $this->keep($keywords, 'en'));
    }
}
