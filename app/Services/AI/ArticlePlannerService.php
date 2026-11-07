<?php

namespace App\Services\AI;

use App\Models\Article;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ArticlePlannerService
{
    public function __construct(
        private readonly ArticleWriterService $writer,
    ) {}
    /**
     * @return array<int, array<string, mixed>> لیست خام action ها (هنوز validate نشده)
     */
    public function plan(Article $article, array $history, string $userMessage): array
    {
        Log::info('ai.article_planner.plan.start', [
            'article_id' => $article->id,
            'user_message' => $userMessage,
        ]);

        $sections = $article->sections()
            ->orderBy('position')
            ->get(['id', 'heading', 'position'])
            ->map(fn ($s) => "section_id={$s->id} | position={$s->position} | title=\"{$s->heading}\"")
            ->implode("\n");

        $historyText = collect($history)
            ->map(fn (array $m) => ($m['role'] === 'user' ? 'کاربر' : 'دستیار').': '.$m['content'])
            ->implode("\n");

        $system = <<<PROMPT
تو یک برنامه‌ریز (Planner) برای ویرایش مقاله هستی و بخشی از دستیار هوشمند «سئوبات» محسوب می‌شوی.
وظیفه‌ی تو این است که بر اساس درخواست کاربر، تصمیم بگیری «چه کارهایی» باید روی مقاله انجام شود.
تو هرگز محتوای نهایی تولید نمی‌کنی و هرگز متن section را نمی‌نویسی. فقط لیستی از action برمی‌گردانی.

انواع مجاز action (فقط از همین‌ها استفاده کن):
- UPDATE_SECTION: نیازمند section_id و instruction (توضیح کوتاه اینکه چه تغییری در آن section باید اعمال شود). این پیش‌فرضِ اول توست هر وقت درخواست کاربر مربوط به «یک بخش مشخص» از مقاله باشد (چه با اسم، چه با موقعیت مثل «بخش آخر»، «مقدمه»، «نتیجه‌گیری»، «بخش سوم»).
- ADD_SECTION: نیازمند section_title (عنوان section جدیدی که باید اضافه شود) و after_section_id (بعد از کدام section اضافه شود؛ اگر اول مقاله باشد null بگذار) و instruction (توضیح محتوای مدنظر)
- DELETE_SECTION: نیازمند section_id
- MOVE_SECTION: نیازمند section_id و new_position (عدد صحیح، از ۱ شروع می‌شود)
- CHANGE_TITLE: نیازمند new_title (عنوان جدید کل مقاله)
- CHANGE_META: نیازمند meta_title و/یا meta_description (هرکدام که کاربر خواسته)
- REWRITE_ARTICLE: بدون پارامتر اضافه، یعنی تمام section ها باید بازنویسی شوند ولی ساختار حفظ شود. فقط وقتی استفاده کن که کاربر واقعاً کل مقاله را مدنظر دارد، نه یک بخش خاص.
- CHANGE_TONE: نیازمند tone (توصیف لحن جدید، مثلاً "رسمی‌تر"، "صمیمی‌تر"). به‌صورت پیش‌فرض روی کل مقاله اعمال می‌شود. اگر کاربر صراحتاً فقط لحن یک بخش خاص را خواسته تغییر کند، section_id همان بخش را هم مقداردهی کن تا فقط همان بخش عوض شود؛ در غیر این صورت section_id را null بگذار.
- SHORTEN_OR_EXPAND_ARTICLE: نیازمند direction ("shorten" یا "expand") و توضیح میزان آن در instruction. به‌صورت پیش‌فرض روی کل مقاله اعمال می‌شود. اگر کاربر صراحتاً فقط یک بخش خاص (مثلاً «بخش آخر»، «نتیجه‌گیری»، یا یک عنوان مشخص) را خواسته کوتاه/بلند شود، section_id همان بخش را هم مقداردهی کن تا فقط همان بخش تغییر کند؛ در غیر این صورت section_id را null بگذار تا کل مقاله شامل شود.

قوانین مهم درباره‌ی تشخیص «یک بخش» در مقابل «کل مقاله»:
- هر وقت درخواست کاربر به‌وضوح فقط مربوط به یک بخش خاص است (نه کل مقاله)، همیشه ترجیح بده از UPDATE_SECTION با section_id درست استفاده کنی — حتی اگر عبارت درخواست شبیه "طولانی‌تر کن"، "لحنش را عوض کن" یا "کوتاه‌ترش کن" باشد که در نگاه اول شبیه REWRITE_ARTICLE/CHANGE_TONE/SHORTEN_OR_EXPAND_ARTICLE به نظر می‌رسد.
- کلماتی مثل «بخش آخر»، «نتیجه‌گیری»، «مقدمه»، «بخش [عنوان]»، «پاراگراف X» نشانه‌ی قطعی هستند که باید section_id مشخص شود و کل مقاله دست نخورد.
- فقط زمانی REWRITE_ARTICLE، یا CHANGE_TONE/SHORTEN_OR_EXPAND_ARTICLE بدون section_id بفرست که کاربر عباراتی مثل «کل مقاله»، «همه‌ی مقاله»، «سراسر متن» به‌کار برده باشد یا از قرینه‌ی مکالمه مشخص باشد که منظورش همه‌جای مقاله است.
- section_title مخصوص ADD_SECTION است (عنوان بخش جدید) و new_title مخصوص CHANGE_TITLE است (عنوان کل مقاله)؛ این دو را هرگز با هم اشتباه نگیر.
- برای هر درخواست، حداقل تعداد action لازم را انتخاب کن، نه بیشتر.
- هرگز متن section، عنوان section، یا محتوای نهایی را در instruction ننویس؛ instruction فقط باید «چه کاری» را توصیف کند نه «متن نهایی چیست».

قانون مربوط به پیام‌های نامرتبط:
- اگر پیام کاربر اصلاً مرتبط با ویرایش/تغییر مقاله نیست (مثلاً سؤال عمومی، گپ‌وگفت، سؤال درباره‌ی خودت، یا هر موضوع بی‌ربط دیگر)، آرایه‌ی actions را کاملاً خالی برگردان و در reply، خودت را به‌عنوان دستیار هوشمند «سئوبات» معرفی کن؛ توضیح بده که وظیفه‌ات کمک به نوشتن، ویرایش و بهینه‌سازی سئوی مقالات همین سایت است (نه پاسخ‌گویی به سؤالات عمومی نامرتبط)، و از کاربر بخواه در صورت تمایل درخواست خودش را در قالب یک تغییر مشخص روی مقاله (مثلاً ویرایش یک بخش، تغییر عنوان، یا افزودن بخش جدید) مطرح کند.
- اگر پیام کاربر یک سؤال معمولی درباره‌ی خودِ مقاله (نه یک درخواست تغییر) است، باز هم actions را خالی بگذار ولی reply باید مستقیماً به سؤال او پاسخ دهد.
- reply را به همان زبانی بنویس که کاربر پیامش را نوشته (reply مشمول قانون زبان خروجی نیست)؛ اما section_title، new_title، meta_title و meta_description محتوای خود مقاله‌اند و باید به زبان مقاله باشند.
PROMPT;

        $user = <<<PROMPT
مقاله: «{$article->title}»

ساختار فعلی section های مقاله:
{$sections}

تاریخچه‌ی مکالمه:
{$historyText}

پیام جدید کاربر:
{$userMessage}
PROMPT;

        try {
            $result = $this->writer->chat(
                model: 'gpt-5-nano',
                system: $system,
                user: $user,
                schemaName: 'article_plan',
                schema: [
                    'type' => 'object',
                    'properties' => [
                        'actions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => [
                                            'UPDATE_SECTION', 'ADD_SECTION', 'DELETE_SECTION',
                                            'MOVE_SECTION', 'CHANGE_TITLE', 'CHANGE_META',
                                            'REWRITE_ARTICLE', 'CHANGE_TONE', 'SHORTEN_OR_EXPAND_ARTICLE',
                                        ],
                                    ],
                                    'section_id' => ['type' => ['integer', 'null']],
                                    'after_section_id' => ['type' => ['integer', 'null']],
                                    'section_title' => ['type' => ['string', 'null']],
                                    'new_title' => ['type' => ['string', 'null']],
                                    'new_position' => ['type' => ['integer', 'null']],
                                    'instruction' => ['type' => ['string', 'null']],
                                    'tone' => ['type' => ['string', 'null']],
                                    'direction' => ['type' => ['string', 'null']],
                                    'meta_title' => ['type' => ['string', 'null']],
                                    'meta_description' => ['type' => ['string', 'null']],
                                ],
                                'required' => [
                                    'type', 'section_id', 'after_section_id', 'section_title', 'new_title',
                                    'new_position', 'instruction', 'tone', 'direction',
                                    'meta_title', 'meta_description',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'reply' => ['type' => 'string'],
                    ],
                    'required' => ['actions', 'reply'],
                    'additionalProperties' => false,
                ],
                temperature: 0.3,
                language: ContentLanguage::forSite($article->site),
            );
        } catch (Throwable $e) {
            Log::error('ai.article_planner.plan.failed', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (! isset($result['actions']) || ! is_array($result['actions'])) {
            Log::error('ai.article_planner.plan.invalid_response', [
                'article_id' => $article->id,
                'result' => $result,
            ]);

            throw new RuntimeException('The AI returned an invalid plan.');
        }

        Log::info('ai.article_planner.plan.done', [
            'article_id' => $article->id,
            'actions_count' => count($result['actions']),
        ]);

        return $result; // ['actions' => [...], 'reply' => '...']
    }
}
