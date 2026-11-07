<?php

namespace App\Services\AI;

use App\Models\Site;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class KeywordSelectionService
{
    use RecordsTokenUsage;
    public function select(Site $site, array $keywordResults): array
    {
        $apiKey = config('seobot.openai.api_key');
        if (! $apiKey) {
            throw new InvalidArgumentException('The AI API key is not configured.');
        }

        $contentBlock = $this->buildContentBlock($site, $keywordResults);

        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->post(rtrim(config('seobot.openai.base_url'), '/').'/chat/completions', [
                'model' => 'gpt-5-nano',
                // 'temperature' => 0.4,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'keyword_selection',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'final_keyword' => [
                                    'type' => 'string',
                                    'description' => 'بهترین و نهایی‌ترین کلمه کلیدی منتخب، به زبان سایت.',
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'عنوان پیشنهادی برای محتوا بر اساس کلمه کلیدی نهایی.',
                                ],
                                'reasoning' => [
                                    'type' => 'string',
                                    'description' => 'دلیل کوتاه انتخاب، بر اساس داده‌های ترند.',
                                ],
                            ],
                            'required' => ['final_keyword', 'title', 'reasoning'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'messages' => [
                    ['role' => 'system', 'content' => ContentLanguage::localize($this->systemPrompt(), ContentLanguage::forSite($site))],
                    ['role' => 'user', 'content' => $contentBlock],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Selecting the final keyword failed: '.$response->body());
        }

        $message = $response->json('choices.0.message.content');
        if (! $message) {
            throw new RuntimeException('No response from the AI.');
        }

        $usage = $response->json('usage');
        if ($usage && $site->user) {
            $this->recordAiTokenUsage(
                user: $site->user,
                serviceName: 'ai.keyword_selection',
                usage: $usage,
                model: 'gpt-5-nano',
            );
        }

        $parsed = json_decode($message, true);
        if (! is_array($parsed) || ! isset($parsed['final_keyword'], $parsed['title'])) {
            throw new RuntimeException('The AI response couldn\'t be processed.');
        }

        return $parsed;
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
تو یک استراتژیست ارشد سئو (SEO) و تولید محتوا هستی که تخصص کامل روی بازار فارسی‌زبان (ایران) داری.
وظیفه‌ات این است که بر اساس داده‌های واقعی گوگل ترندز (شامل «کوئری‌های پرتکرار - top» و «کوئری‌های رو-به-رشد - rising» برای چند کلمه کلیدی مختلف)، دقیقاً یک کلمه کلیدی نهایی و یک عنوان (Title) پیشنهادی انتخاب کنی.

اولویت انتخاب کلمه کلیدی نهایی:
۱. عبارت‌هایی با برچسب "Breakout" در بخش rising (یعنی رشد بیش از ۵۰۰۰٪) - چون فرصت لحظه‌ای و داغ بازار هستند.
۲. عبارت‌هایی با بالاترین درصد رشد در rising که Breakout نیستند.
۳. عبارت‌هایی که در top queries به‌طور پایدار و مکرر تکرار شده‌اند و حجم جست‌وجوی بالایی نشان می‌دهند.
۴. ارتباط معنایی و تجاری کلمه با حوزه فعالیت (niche) و مخاطب هدف سایت که در ادامه توضیح داده می‌شود.
۵. اجتناب از کلمات بیش‌ازحد کلی یا مبهم؛ ترجیح با عبارات مشخص و قابل هدف‌گیری (long-tail در صورت امکان).

قوانین سخت‌گیرانه:
- کلمه کلیدی نهایی می‌تواند دقیقاً یکی از کلمات ورودی باشد، یا ترکیب طبیعی یک کلمه ورودی با یکی از عبارات پرتکرار/رو-به-رشد مرتبط با آن.
- هرگز کلمه‌ای که هیچ پشتوانه‌ای در داده‌های ارائه‌شده ندارد را اختراع نکن.
- اگر داده معناداری برای هیچ‌کدام از کلمات وجود نداشت، مرتبط‌ترین کلمه ورودی را بر اساس موضوع سایت انتخاب کن و دلیلش را در reasoning بنویس.
- عنوان باید کوتاه (حدود ۶۰ تا ۷۰ کاراکتر)، جذاب، و بهینه برای موتور جست‌وجو باشد؛ کلمه کلیدی نهایی باید طبیعی در آن جا بگیرد، نه به‌صورت چسبانده‌شده.
- از کلیک‌بیت گمراه‌کننده یا وعده نادرست در عنوان خودداری کن.
- فقط یک کلمه کلیدی و یک عنوان بده، نه چند گزینه یا لیست.
- تمام خروجی به زبان فارسی روان باشد.
- هیچ متنی خارج از ساختار JSON درخواستی تولید نکن.
PROMPT;
    }

    protected function buildContentBlock(Site $site, array $keywordResults): string
    {
        $lines = [
            "دامنه: {$site->domain}",
            'حوزه فعالیت (niche): '.($site->niche ?? 'نامشخص'),
            'خلاصه سایت: '.($site->summary ?? 'نامشخص'),
            '',
            'داده‌های گوگل ترندز برای هر کلمه کلیدی:',
        ];

        foreach ($keywordResults as $index => $result) {
            $number = $index + 1;
            $lines[] = "--- کلمه کلیدی {$number}: {$result['keyword']} ---";

            $lines[] = ! empty($result['top'])
                ? 'کوئری‌های پرتکرار (top): '.implode(' | ', array_map(
                    fn ($row) => ($row['query'] ?? '').' (امتیاز: '.($row['value'] ?? '?').')',
                    $result['top']
                ))
                : 'کوئری‌های پرتکرار (top): داده‌ای موجود نیست';

            $lines[] = ! empty($result['rising'])
                ? 'کوئری‌های رو-به-رشد (rising): '.implode(' | ', array_map(
                    fn ($row) => ($row['query'] ?? '').' (رشد: '.($row['change'] ?? '?').')',
                    $result['rising']
                ))
                : 'کوئری‌های رو-به-رشد (rising): داده‌ای موجود نیست';

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
