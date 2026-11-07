<?php

namespace App\Services\AI;

use App\Models\Site;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class KeywordSelectionSuggestionsService
{
    use RecordsTokenUsage;
    /**
     * @param Site $site
     * @param array $keywordResults  [['keyword' => '...', 'suggestions' => [['value'=>.., 'relevance'=>..], ...]], ...]
     * @param array $usedKeywords    قبلاً استفاده‌شده: ['keyword' => 'title', 'keyword2' => 'title2', ...]
     */
    public function select(Site $site, array $keywordResults, array $usedKeywords = []): array
    {
        $apiKey = config('seobot.openai.api_key');
        if (! $apiKey) {
            throw new InvalidArgumentException('The AI API key is not configured.');
        }

        $contentBlock = $this->buildContentBlock($site, $keywordResults, $usedKeywords);

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
                                    'description' => 'بهترین و نهایی‌ترین کلمه کلیدی منتخب، به زبان سایت. نباید در لیست کلمات قبلاً استفاده‌شده باشد.',
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'عنوان پیشنهادی برای محتوا بر اساس کلمه کلیدی نهایی. نباید تکراری یا مشابه عناوین قبلی باشد.',
                                ],
                                'reasoning' => [
                                    'type' => 'string',
                                    'description' => 'دلیل کوتاه انتخاب، بر اساس داده‌های autocomplete و عدم تکرار با موارد قبلی.',
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
                serviceName: 'ai.keyword_selection_suggestions',
                usage: $usage,
                model: 'gpt-5-nano',
            );
        }

        $parsed = json_decode($message, true);
        if (! is_array($parsed) || ! isset($parsed['final_keyword'], $parsed['title'])) {
            throw new RuntimeException('The AI response couldn\'t be processed.');
        }

        // محافظت نهایی در کد: اگر AI به هر دلیلی دقیقاً همون کلمه قبلی رو برگردوند، خطا بده
        // تا Job بتونه retry/fail کنه به‌جای ذخیره یه رکورد تکراری.
        if (array_key_exists($parsed['final_keyword'], $usedKeywords)) {
            throw new RuntimeException("The keyword '{$parsed['final_keyword']}' has already been used for this site.");
        }

        return $parsed;
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
تو یک استراتژیست ارشد سئو (SEO) و تولید محتوا هستی که تخصص کامل روی بازار فارسی‌زبان (ایران) داری.
وظیفه‌ات این است که بر اساس داده‌های واقعی Google Autocomplete (پیشنهادات real-time جست‌وجوی گوگل به همراه امتیاز relevance برای چند کلمه کلیدی مختلف)، دقیقاً یک کلمه کلیدی نهایی و یک عنوان (Title) پیشنهادی انتخاب کنی.

اولویت انتخاب کلمه کلیدی نهایی:
۱. پیشنهادهایی با بالاترین امتیاز relevance، چون نشان‌دهنده محبوبیت و حجم جست‌وجوی بالاتر هستند.
۲. پیشنهادهایی که به‌طور طبیعی و پرکاربرد در بین چند کلمه ورودی مختلف تکرار شده‌اند.
۳. ارتباط معنایی و تجاری کلمه با حوزه فعالیت (niche) و مخاطب هدف سایت که در ادامه توضیح داده می‌شود.
۴. اجتناب از کلمات بیش‌ازحد کلی یا مبهم؛ ترجیح با عبارات مشخص و قابل هدف‌گیری (long-tail در صورت امکان).

قانون عدم تکرار (بسیار مهم):
- در پیام کاربر ممکن است لیستی با عنوان «کلمات کلیدی و موضوعاتی که قبلاً برای این سایت استفاده شده‌اند» ارائه شود.
- تحت هیچ شرایطی نباید کلمه کلیدی نهایی را دقیقاً برابر با یکی از آن موارد انتخاب کنی.
- همچنین باید از انتخاب کلمه یا موضوعی که از نظر معنایی و محتوایی بسیار نزدیک یا هم‌پوشان با موارد قبلی است (حتی اگر عبارت دقیقاً یکسان نباشد) خودداری کنی، چون باعث تولید محتوای تکراری می‌شود.
- در صورتی که تمام گزینه‌های موجود در داده‌های autocomplete با موارد قبلی همپوشانی معنایی داشتند، باید یک زاویه (angle) یا زیرموضوع جدید و متفاوت از همان کلمه ورودی انتخاب کنی و در reasoning توضیح بده چرا این گزینه را متفاوت از موارد قبلی می‌دانی.

قوانین سخت‌گیرانه:
- کلمه کلیدی نهایی می‌تواند دقیقاً یکی از کلمات ورودی باشد، یا یکی از پیشنهادهای autocomplete مرتبط با آن‌ها.
- هرگز کلمه‌ای که هیچ پشتوانه‌ای در داده‌های ارائه‌شده ندارد را اختراع نکن.
- اگر داده معناداری برای هیچ‌کدام از کلمات وجود نداشت، مرتبط‌ترین کلمه ورودی را بر اساس موضوع سایت انتخاب کن و دلیلش را در reasoning بنویس.
- عنوان باید کوتاه (حدود ۶۰ تا ۷۰ کاراکتر)، جذاب، و بهینه برای موتور جست‌وجو باشد؛ کلمه کلیدی نهایی باید طبیعی در آن جا بگیرد، نه به‌صورت چسبانده‌شده.
- از کلیک‌بیت گمراه‌کننده یا وعده نادرست در عنوان خودداری کن.
- فقط یک کلمه کلیدی و یک عنوان بده، نه چند گزینه یا لیست.
- تمام خروجی به زبان فارسی روان باشد.
- هیچ متنی خارج از ساختار JSON درخواستی تولید نکن.
PROMPT;
    }

    protected function buildContentBlock(Site $site, array $keywordResults, array $usedKeywords = []): string
    {
        $lines = [
            "دامنه: {$site->domain}",
            'حوزه فعالیت (niche): '.($site->niche ?? 'نامشخص'),
            'خلاصه سایت: '.($site->summary ?? 'نامشخص'),
            '',
        ];

        if (! empty($usedKeywords)) {
            $lines[] = 'کلمات کلیدی و موضوعاتی که قبلاً برای این سایت استفاده شده‌اند (هرگز دوباره انتخاب نکن):';
            foreach ($usedKeywords as $keyword => $title) {
                $lines[] = "- کلمه کلیدی: {$keyword} | عنوان قبلی: ".($title ?: 'نامشخص');
            }
            $lines[] = '';
        }

        $lines[] = 'داده‌های Google Autocomplete برای هر کلمه کلیدی:';

        foreach ($keywordResults as $index => $result) {
            $number = $index + 1;
            $lines[] = "--- کلمه کلیدی {$number}: {$result['keyword']} ---";

            $lines[] = ! empty($result['suggestions'])
                ? 'پیشنهادات: '.implode(' | ', array_map(
                    fn ($row) => ($row['value'] ?? '').' (امتیاز: '.($row['relevance'] ?? '?').')',
                    $result['suggestions']
                ))
                : 'پیشنهادات: داده‌ای موجود نیست';

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
