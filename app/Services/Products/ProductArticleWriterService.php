<?php

namespace App\Services\Products;

use App\Models\ProductArticle;
use App\Models\ProductArticleSection;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;
use App\Services\AI\ChatModelResolver;
use App\Services\AI\Concerns\PolishesLines;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use App\Services\AI\RecordsTokenUsage;
use App\Services\Image\ImageFileName;
use App\Services\Products\Concerns\BuildsArticleSeo;
use App\Services\Products\Concerns\FormatsProductData;
use App\Support\ContentLanguage;
use App\Support\PersianText;
use App\Support\PersianWritingSkill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * تولید مقاله‌ی محصول برای هر فروشگاه و هر حوزه‌ای:
 * Brief → Assignments (اسکلت) → هدینگ محصولات → Section ها → assemble → متا/اسکیما.
 * هیچ چیز در این کلاس به نوع کالای خاصی وابسته نیست؛ همه‌چیز از عنوان، دسته‌ها و داده‌ی محصولات می‌آید.
 */
class ProductArticleWriterService
{
    use BuildsArticleSeo;
    use FormatsProductData;
    use PolishesLines;
    use RecordsTokenUsage;
    use UsesGapgptApiKey;

    /**
     * برچسب‌های ثابتی که کد (نه AI) داخل مقاله می‌گذارد، برای سایت‌های غیرفارسی.
     * shortcut: همه‌ی زبان‌های غیرفارسی برچسب انگلیسی می‌گیرند؛ اگر سایت آلمانی/عربی/... جدی شد، جدول همان زبان اضافه شود.
     */
    protected const LABELS_EN = [
        'مقدمه' => 'Introduction',
        'جدول مقایسه سریع محصولات' => 'Quick Product Comparison',
        'سوالات متداول' => 'Frequently Asked Questions',
        'جمع‌بندی' => 'Conclusion',
        '%s: جدول مقایسه سریع' => '%s: Quick Comparison',
        'سوالات متداول درباره %s' => '%s: Frequently Asked Questions',
        '%s: جمع‌بندی و انتخاب نهایی' => '%s: Final Verdict',
        'بررسی' => 'Overview',
        'جدول مقایسه سریع' => 'Quick comparison table',
        'محصولات این مقاله:' => 'Products in this article:',
        'محصول' => 'Product',
        'قیمت' => 'Price',
        'مشخصات' => 'Highlights',
        'ارزان‌ترین گزینه‌ی این مقایسه' => 'Lowest-priced option here',
        'گران‌ترین گزینه‌ی این مقایسه' => 'Highest-priced option here',
        'قیمت در رده‌ی میانی' => 'Mid-range price',
        'تصویر شاخص مقاله' => 'Article featured image',
        ' در برابر ' => ' vs ',
        ' و ' => ' & ',
    ];

    /** زبان سایتِ مقاله‌ی در حال پردازش؛ هر متد عمومی در ابتدا با useLanguageOf() تنظیمش می‌کند. */
    protected string $language = ContentLanguage::DEFAULT;

    protected const CHEAP_MODEL = 'gpt-4o-mini';
    protected const REASONING_EFFORT = 'low';

    /** واحد پیش‌فرض نمایش قیمت (وقتی ستون currency خالی است قیمت‌ها تومان فرض می‌شوند). */
    protected const PRICE_UNIT = ''; // unknown currency: the number alone

    /** schema.org کد رسمی تومان ندارد؛ تومان → ریال (IRR). */
    protected const PRICE_SCHEMA_MULTIPLIER = 10;

    /** true = جدول مقایسه HTML، false = جدول Markdown (متن بخش‌ها هرگز HTML ندارد). */
    protected const TABLE_AS_HTML = false;

    /** false = جدول مقایسه بدون ستون «قیمت» (قیمت فقط در کارت محصول سایت دیده می‌شود، نه در متن مقاله). */
    protected const TABLE_SHOW_PRICE = false;

    protected const STR = ['type' => 'string'];

    protected const HEADINGS = [
        'introduction' => 'مقدمه',
        'comparison' => 'جدول مقایسه سریع محصولات',
        'faq' => 'سوالات متداول',
        'conclusion' => 'جمع‌بندی',
    ];

    protected const HEADING_KEYWORD_TEMPLATES = [
        'comparison' => '%s: جدول مقایسه سریع',
        'faq' => 'سوالات متداول درباره %s',
        'conclusion' => '%s: جمع‌بندی و انتخاب نهایی',
    ];

    /** [حداقل، حداکثر، پیش‌فرض] کلمات هر نوع بخش؛ عدد AI داخل این بازه کلمپ می‌شود. */
    protected const WORD_RANGES = [
        'introduction' => [90, 140, 110],
        'comparison' => [70, 130, 100],
        'product' => [240, 360, 290],
        'guide' => [220, 340, 280],
        'faq' => [260, 420, 320],
        'conclusion' => [90, 140, 110],
    ];

    protected const STYLE_HINTS = [
        'price' => 'قیمت و اقتصادی: مخاطب قیمت روز و گزینه‌ی مناسب بودجه‌اش را می‌خواهد. ساختار: مقدمه، بخش هر محصول از ارزان به گران، comparison (جدول مقایسه سریع) بلافاصله بعد از آخرین محصول، یک guide درباره‌ی عوامل مؤثر بر قیمت، faq، جمع‌بندی.',
        'comparison' => 'مقایسه: مخاطب بین چند گزینه مردد است. ساختار: مقدمه، بخش هر محصول، comparison (جدول مقایسه سریع) بلافاصله بعد از آخرین محصول، یک guide با عنوان «کدام را انتخاب کنیم؟»، faq، جمع‌بندی.',
        'buying_guide' => 'راهنمای خرید: مخاطب می‌خواهد درست انتخاب کند. ساختار: مقدمه، guide معیارهای انتخاب، بخش هر محصول از اقتصادی تا لوکس، comparison (جدول مقایسه سریع) بلافاصله بعد از آخرین محصول، faq، جمع‌بندی.',
    ];

    protected const IMAGE_STYLES = [
        'minimal' => 'سبک مینیمال: فضای منفی زیاد، حداقل عنصر در قاب، پالت رنگی ملایم و محدود، ترکیب‌بندی ساده و تمیز.',
        'infographic' => 'سبک اینفوگرافیک: شکل‌های هندسی ساده و flat، ترکیب‌بندی شبیه دیاگرام/آیکون، رنگ‌های تخت و پررنگ.',
        'conceptual' => 'سبک مفهومی/هنری: تصویرسازی نمادین، ترکیب خلاقانه‌ی عناصر بصری، نورپردازی دراماتیک.',
        'realistic' => 'سبک واقع‌گرایانه/ادیتوریال: عکاسی طبیعی و مستندگونه، نورپردازی واقعی، حس مجله‌ای.',
        'product' => 'سبک محصول‌محور: عکاسی استودیویی، پس‌زمینه‌ی تمیز و ساده، نورپردازی حرفه‌ای تبلیغاتی، فوکوس دقیق روی محصول.',
    ];

    protected const NO_TEXT_SUFFIX_EN = 'No text, no letters, no numbers, no logos, no watermarks anywhere in the image.';

    /** شروع‌کننده‌ی متفاوت برای هر بخش محصول؛ جلوی الگوی تکراری را می‌گیرد. */
    protected const OPENING_MOVES = [
        'Open with a small hypothetical scene where someone chooses or uses this kind of product, framed as hypothetical («فرض کنید...»، «مثلاً اگر...»); never present it as something that really happened. One or two sentences, then move to the product.',
        'Open with what the product name itself says (theme, line, model), stated plainly, then say who it speaks to.',
        'Open with the price position in plain words (the cheapest of the group, a step above the entry model, the top of the range) and what that tier means for the buyer compared with the neighbouring option; never an amount, a currency or a rank number. Then widen to who it suits.',
        'Open with a short honest question the buyer is probably asking about this model, then answer it directly.',
        'Open with one widely known, certain fact about the brand or product type that matters for this choice, then connect it to this model.',
    ];

    /** عبارت‌های کلیشه‌ای؛ اگر در خروجی دیده شوند یک بار بازنویسی هدفمند انجام می‌شود. */
    protected const CLICHES = [
        ...PersianText::AI_CLICHES,
        'سلیقه‌های خاص', 'سلیقه خاص', 'جدول ما', 'در لیست ما', 'لیست ما', 'فهرست ما',
    ];

    /** واژه‌های ممنوع در ستون «جایگاه» جدول و هدینگ محصول. */
    protected const ANGLE_BANNED = ['خاص', 'جذاب', 'زیبا', 'منحصر', 'عالی', 'ایده‌آل', 'بی‌نظیر', 'باکیفیت', 'با کیفیت', 'فوق‌العاده'];

    /** بخش‌هایی که ذکر قیمت در آن‌ها ممنوع است: همه‌ی بخش‌ها (قیمت فقط در کارت محصول سایت نمایش داده می‌شود، نه در متن مقاله). */
    protected const NO_PRICE_TYPES = ['introduction', 'comparison', 'guide', 'product', 'faq', 'conclusion'];

    /** سقف هدف هدینگ (AI باید زیر این عدد بنویسد و اعتبارسنجی هم همین را می‌خواهد). */
    protected const MAX_HEADING_CHARS = 70;

    /** فقط شبکه‌ی ایمنی: هدینگ بلندتر از این (که از AI نیامده) روی مرز عبارت/کلمه بریده می‌شود. */
    protected const HARD_MAX_HEADING_CHARS = 100;

    /** جداکننده‌ی نام کوتاه محصول و زاویه در هدینگ محصول. */
    protected const HEADING_SEPARATOR = '؛';

    /** کلماتی که هدینگ/عبارت نباید با آن‌ها تمام شود. */
    protected const DANGLING_WORDS = [
        'برای', 'با', 'و', 'از', 'به', 'در', 'که', 'تا', 'یا', 'را', 'بر',
        'مثل', 'مانند', 'بین', 'درباره', 'روی', 'مدل', 'همچنین', 'هم',
    ];

    /** سقف تکرار عین عبارت کلیدی در متن هر نوع بخش (لینک محصول هم حساب می‌شود). */
    protected const KEYWORD_MAX_PER_SECTION = [
        'introduction' => 2,
        'conclusion' => 2,
        'faq' => 3,
        'default' => 2,
    ];

    protected const TYPE_RULES = [
        'introduction' => 'Introduction: the focus keyword must appear exactly as written in BRIEF (same word forms, no rephrasing) exactly ONCE within the first two sentences (in the first or the second, not both). Open with a common reader situation (choosing among options of this kind, or within this budget); if it is a scene, frame it as hypothetical («فرض کنید...»). No invented statistics. In one short sentence say the article goes through the selected products of this store one by one, and that a quick comparison table near the end puts them side by side so the reader can decide. Short, no subheadings. Do NOT name any product or brand, do NOT mention any price, and do NOT give a verdict: later sections do that. If a LINK line is given, include that link once.',

        'comparison' => 'Comparison: this section comes AFTER the reader has read every product section, and a table with every product and its angle is added automatically above your text (the table has no prices, and neither may your text). Write a compact decision aid, 70 to 120 words, at most 2 short paragraphs. Do NOT repeat prices or angles, do NOT re-describe individual models, do NOT list products one by one. Say only what the table cannot: how the products split into price tiers, what stepping up a tier changes as far as the data supports, then one clear rule of thumb ("if X matters most look at the cheaper tier, if Y the upper one"). You may refer back to products by short name (at most 3 names), as something the reader has just seen. No prices. If a LINK line is given, include that link once.',

        'guide' => 'Guide: practical, specific advice for choosing this kind of product, built on the brief buying_criteria. Connect each criterion to a concrete situation, and when natural tie it to the selected products by short name. Include at least one common mistake buyers make.',

        'product' => 'Product section: write it like a shop advisor explaining one model to a friend. Cover, in an order that fits your opening move: what this model is (taken from the name), the kind of buyer or occasion it fits, where it sits among its neighbours (use PRICE POSITION in plain words: never a rank number and NEVER a price figure), and one honest limit or thing to check (from the data or from certain general knowledge about the product type; never an invented flaw). Do NOT write any price, amount or currency (تومان، ریال، دلار) and no number that works as a price anywhere in the section; the price is shown elsewhere on the page. No subheadings, no bullet list unless truly natural, at most one bold phrase. Not an ad.',

        'faq' => 'FAQ: write each given subheading (question) exactly as an H3, immediately followed by its OWN direct answer of 2 to 4 complete sentences that starts with the answer itself. Every H3 MUST have an answer; never leave an answer empty, never merge two answers, never stop before the last question. Store-policy questions (warranty, shipping, returns, authenticity): never promise anything; give general safe guidance and tell the reader to check the product page or ask the store. If no subheadings are given, write 4 common buyer questions yourself.',

        'conclusion' => 'Conclusion: recap which product fits which kind of buyer (short names, no product links, no prices), mention the focus keyword exactly once, naturally, introduce nothing new, end with one natural, non-pushy invitation to look at the products. If a LINK line is given, include that link once.',
    ];

    protected const BRIEF_SYSTEM = <<<'PROMPT'
شما استراتژیست ارشد سئو و محتوای فروشگاهی هستید. برای یک «مقاله محصول» فارسی Brief بسازید؛ مقاله‌ای که چند محصول واقعی همین فروشگاه را معرفی/مقایسه می‌کند و باید مثل توصیه‌ی یک مشاور فروش باتجربه خوانده شود، نه بروشور. فروشگاه می‌تواند در هر حوزه‌ای باشد؛ فقط از روی عنوان، دسته‌ها و نام محصولات نتیجه بگیر.
قوانین:
1. audience: مخاطب در یک جمله‌ی کوتاه؛ خریدار معمولی که متخصص این حوزه نیست، با موقعیت مشخصش (مثلاً «کسی که برای خانه اولین مودم فیبر را می‌خرد»).
2. focus_keyword: عبارت ۲ تا ۴ کلمه‌ای طبیعی و پرجستجو (شکل رایج جستجوی خریدار)، ترجیحاً عبارتی که عیناً در خود عنوان مقاله هم هست. نام برند یا مدلی که در محصولات نیست نیاور. به‌جز نام برند و مدل، کلمه‌ها فارسی و روزمره باشند (بدون اصطلاح تخصصی). همین عبارت عیناً در تیترها، مقدمه و متا استفاده می‌شود؛ در انتخابش دقت کن.
3. search_intent: یکی از commercial، transactional، informational.
4. tone: پیش‌فرض «ساده، صمیمی و کاربردی، خطاب شما»؛ فقط اگر کاربر لحن دیگری خواسته عوضش کن. همیشه یک نوع خطاب واحد (شما یا تو)، نه ترکیب.
5. hook: یک جمله‌ی مشخص برای شروع مقدمه؛ دغدغه‌ی رایج خریدار این نوع محصول. اگر صحنه است، فرضی بگو («فرض کنید...») و اتفاق واقعی جا نزن. آمار ساختگی و شروع کلیشه‌ای («امروزه»، «در دنیای امروز») ممنوع.
6. price_story: یک جمله درباره‌ی اینکه محصولات از نظر جایگاه قیمتی چطور دسته‌بندی می‌شوند (اقتصادی، میان‌رده، بالا) و چه چیزی بین گزینه‌ها فرق می‌سازد، فقط از روی نام‌ها و ترتیب قیمتی. هیچ عدد قیمتی، مبلغ یا واحد پول ننویس؛ حساب و حدس هم نکن.
7. reader_questions: ۴ تا ۵ سؤال واقعی خریدار درباره‌ی انتخاب، استفاده و نگهداری این نوع محصول. سؤال درباره‌ی سیاست فروشگاه (ضمانت، ارسال، مرجوعی، اصالت) ننویس. هر سؤال کوتاه و کامل باشد (حداکثر ۱۰ کلمه)، با همان کلمه‌هایی که خریدار معمولی می‌پرسد.
8. buying_criteria: حداکثر ۵ معیار انتخاب عمومی و شناخته‌شده، هرکدام حداکثر ۶ کلمه، با کلمه‌های روزمره (معیار فنی را ساده بگو: «صدای بلند و تمیز» نه «توان خروجی RMS»). مشخصات ساختگی برای مدل‌های داده‌شده ننویس.
9. product_angles: برای هر محصول دقیقاً یک زاویه‌ی ۳ تا ۷ کلمه‌ای کامل و مستقل، مشخص و متفاوت از بقیه، بر اساس نام/طرح، جایگاه قیمتی نسبت به بقیه و موقعیت واقعی استفاده یا هدیه. زاویه باید بدون هیچ ادامه‌ای معنی کامل داشته باشد و هرگز با حرف اضافه («برای»، «با»، «از»، «به»، «در»، «و») تمام نشود. با «مناسب برای» شروع نکن و «؛» یا نقطه‌ی پایانی نگذار. این واژه‌ها ممنوع‌اند: {BANNED}. هیچ عدد قیمت، مبلغ یا واحد پول در زاویه ننویس (جایگاه قیمتی را فقط با کلمه بگو، مثل «ارزان‌ترین»). جمله‌ی عمومی که به هر محصولی بخورد ممنوع است (بد: «مناسب برای کسانی که طراحی خاص می‌خواهند»؛ خوب: «گران‌ترین گزینه‌ی لیست، وقتی می‌خواهی هدیه سال‌ها یادگار بماند»). مشخصات فنی نساز.
10. همه خروجی فارسی ساده و روزمره (جز نام برند و مدل)، بدون اصطلاح تخصصی و کلمه‌ی انگلیسیِ فارسی‌نویسی‌شده؛ فقط مقادیر schema.
PROMPT;

    protected const OUTLINE_SYSTEM = <<<'PROMPT'
شما متخصص ساختاردهی محتوای سئو هستید. بر اساس Brief و محصولات واقعی، ساختار (فقط اسکلت، نه متن) یک مقاله محصول فارسی را طراحی کنید.
قوانین:
1. type ها: introduction، comparison، guide، product، faq، conclusion.
2. بخش اول introduction، بخش آخر conclusion و قبل از آن faq با ۴ تا ۵ سؤال واقعی خریداران در subheadings (ترجیحاً از reader_questions). هر سؤال یک جمله‌ی کوتاه و کامل باشد.
3. دقیقاً یک comparison؛ این بخش در انتهای بخش‌های محصول می‌آید (بعد از همه‌ی product ها). جدول را کد اضافه می‌کند و متن comparison خیلی کوتاه است. key_points فقط ۲ نکته درباره‌ی دسته‌بندی قیمتی و قاعده‌ی تصمیم‌گیری باشد، نه فهرست کردن محصولات.
4. برای هر محصول دقیقاً یک بخش product با product_ids شامل فقط id همان محصول. heading آن فقط یک نام کوتاه موقت است (حداکثر ۶ کلمه)؛ هدینگ نهایی محصول را مرحله‌ی جداگانه‌ای می‌سازد.
5. حداکثر ۲ guide با heading کوتاه و کاربردی (حداکثر ۸ کلمه) که بگوید خواننده چه چیزی یاد می‌گیرد یا چه تصمیمی می‌گیرد (مثلاً «چه مودمی برای خانه بخریم؟»)؛ بدون گیومه، بدون اصطلاح تخصصی و بدون کلمه‌ی انگلیسی (جز نام مدل)، با دستور زبان درست. comparison همیشه بلافاصله بعد از آخرین بخش product قرار می‌گیرد؛ ترتیب بقیه طبق Style.
6. در بخش‌های غیر product، product_ids خالی. subheadings فقط برای faq و در صورت نیاز guide.
7. key_points: ۲ تا ۴ نکته‌ی مشخص برای نویسنده؛ در product بر پایه‌ی نام، جایگاه قیمتی و زاویه‌ی داده‌شده. عدد قیمت ننویس. عبارت کلی مثل «کیفیت بالا» یا «مناسب برای هدیه» به‌تنهایی ممنوع؛ هر نکته یک موقعیت، مقایسه یا تصمیم مشخص باشد. مشخصات فنی ساختگی ننویس.
8. target_words: مقدمه ۹۰–۱۴۰، comparison ۷۰–۱۳۰، هر product ۲۵۰–۳۴۰، guide ۲۳۰–۳۲۰، faq ۲۸۰–۴۰۰، جمع‌بندی ۹۰–۱۴۰.
9. هدینگ بخش‌های comparison، faq و conclusion را کد با عبارت کلیدی کامل می‌کند؛ در هدینگ guide عبارت کلیدی را اجبار نکن و فقط اگر کاملاً طبیعی بود بیاور. هدینگ‌ها H2 فارسی، مشخص، کامل و یکتا باشند (دو هدینگ مشابه نباشد) و هرگز با حرف اضافه («برای»، «با»، «از»...) تمام نشوند. صفت‌های کلی ({BANNED}) در heading ممنوع.
10. هر سؤال faq یک جمله‌ی پرسشی کامل و با «؟» تمام شود، با کلمه‌های روزمره‌ی خریدار.
11. هر بخش باید به وعده‌ی عنوان مقاله کمک کند؛ بخشی که به عنوان ربط ندارد نساز.
12. هیچ نکته‌ای در دو بخش تکرار نشود: key_points بخش‌ها هم‌پوشانی نداشته باشند و guide چیزی را که بخش‌های product می‌گویند تکرار نکند.
PROMPT;

    /** هدینگ بخش محصول: نام کوتاه (تشخیص AI) + زاویه‌ی کامل. */
    protected const PRODUCT_HEADING_SYSTEM = <<<'PROMPT'
شما سردبیر سئوی فروشگاهی هستید. برای هر محصول یک هدینگ H2 فارسی برای بخش معرفی همان محصول بنویس.
قالب دقیق: «نام کوتاه محصول؛ زاویه‌ی کامل» (با «؛» جدا شود، نه خط تیره).
قوانین:
1. نام کامل محصول معمولاً طولانی و ترکیب فارسی/انگلیسی است؛ عیناً کپی نکن و خودت تشخیص بده چه چیزی لازم است: برند (به فارسی)، سری/نام، و شماره‌ی مدل لاتین (عیناً با همان حروف؛ وجودش اجباری است تا محصولات شبیه هم قابل تشخیص باشند). نوع کالا (مثلاً «مودم») را فقط اگر برای فهم موضوع لازم است و جا دارد نگه دار. کلمه‌های زائد و تکراری مثل «مدل» و مشخصات فنی طولانی را حذف کن.
2. ترتیب خوانا: برند فارسی اول، بعد نام/شماره‌ی مدل لاتین.
3. زاویه را از روی angle به یک عبارت کامل و مستقل بازنویسی کن (۳ تا ۷ کلمه) که بدون ادامه هم معنی کامل داشته باشد، با کلمه‌های روزمره، بدون گیومه و اصطلاح تخصصی. هرگز نیمه‌کاره تمام نشود و آخرش حرف اضافه («برای»، «با»، «از»، «به»، «در»، «و») نباشد.
4. طول کل هدینگ حداکثر {MAX} کاراکتر. اگر بلند شد، نام را فشرده‌تر یا زاویه را کوتاه‌تر و کامل بنویس؛ هرگز جمله را نصفه نگذار.
5. هر هدینگ یکتا باشد، نقطه‌ی پایانی نداشته باشد و قیمت یا رتبه نداشته باشد. این واژه‌ها ممنوع‌اند: {BANNED}.
6. هیچ مشخصه‌ی ساختگی اضافه نکن؛ فقط از نام و angle استفاده کن.
7. اگر در ورودی بخش PROBLEMS آمده، دقیقاً همان ایرادها را رفع کن.
مثال خوب: «هوآوی HG8546MGM1؛ اقتصادی‌ترین انتخاب برای اینترنت خانگی»
مثال بد: «مودم فیبر نوری GPON-ONT هوآوی مدل HG8245Q2 - گزینه‌ای قوی برای»
خروجی: برای هر product_id دقیقاً یک heading.
PROMPT;

    protected const SECTION_SYSTEM = <<<'PROMPT'
You are a senior Persian e-commerce copywriter. Write ONE section of a product article.

OUTPUT
- Persian Markdown in "content" only.
- Body only: no H1/H2. Use H3 only if SUBHEADINGS are given or TYPE requires it.
- No HTML, no tables.
- Every sentence must be complete. Never stop in the middle of a sentence, list or answer.

VOICE
- Simple, fluent, everyday Persian, like a friendly shop advisor talking to one ordinary buyer: clear, specific, easy to follow.
- Address the reader in the form given by the BRIEF tone, consistently through the whole section; if unclear use «شما». Never mix «تو» and «شما».
- Short paragraphs, varied sentence length. No hype, filler, repetition or translated phrasing.
- It must read as written by an experienced human, not AI: natural Persian word order, no calques from English («این به شما کمک می‌کند تا...»), never start two consecutive sentences or paragraphs the same way, no habitual lists of three adjectives, no exclamation marks, no emoji, no paragraph that ends by summarizing itself.
- Never fake personal experience («ما خودمان تست کردیم»); show expertise with practical detail (what to check, a common mistake, a trade-off).
- Never talk about the article, a list, a table, ranking or selection. Banned: «در لیست ما»، «جدول ما»، «معرفی می‌کنیم»، and any rank number such as «رتبه ۱ از ۵». Turn PRICE POSITION into plain words (the cheapest of the group, one step above the entry model, the top of the range).
- Brands in Persian script; model numbers and series names exactly as given in PRODUCT DATA (the only Latin letters allowed in the text). Every other word in Persian script.
- Avoid: {CLICHES}.

{PLAIN_LANGUAGE}

CONTENT
- Every paragraph must give the reader a specific, useful point: a use case, a limitation, a comparison or a decision rule.
- No generic claims that could fit any product unchanged.
- Product-specific facts ONLY from PRODUCT DATA.
- Never invent specs, warranty, stock, ratings, reviews, discounts, delivery info or store policies.
- General knowledge about the product type is allowed only when it is clearly general and certain. If in doubt, leave it out.
- Mention only listed products; never mention any other brand or model, even if it appears in the TITLE.
- Any scene or example you make up must be framed as hypothetical («فرض کنید...»، «مثلاً اگر...»); never present it as something that really happened.
- Stay on what the TITLE promises and on this section's job; do not repeat points other sections (OUTLINE) already cover.

PRICE & COMPARISON
- NEVER write any price, amount or currency (تومان، ریال، دلار، or any number meant as a price) anywhere in the text. Prices change and are shown elsewhere on the page, not in the article body. Talk about price only in relative words (cheapest, mid-range, one step above, top of the range).
- Never calculate differences, percentages, savings or rankings.
- Compare only when it helps the reader choose; never force a comparison.

LINKS
- Add links only when a LINK line is provided, using the placeholder exactly as given with a natural Persian anchor. Never write real URLs.

SEO
- Write for the reader and the search intent first. Use the focus keyword exactly only where TYPE asks for it; elsewhere prefer natural variants. Never add text only to insert keywords or links.

LENGTH
- Reach TARGET WORDS (up to +15%) with useful information only.

FINAL CHECK
Before output remove anything generic, repetitive, promotional, meta, unsupported or forced.
PROMPT;

    protected const META_SYSTEM = <<<'PROMPT'
شما متخصص سئوی تکنیکال هستید و باید فیلدهای متای یک «مقاله محصول» فروشگاهی فارسی را دقیقاً بر اساس محتوای واقعی همان مقاله بسازید. ورودی: عنوان، سرفصل‌ها و ابتدای متن نهایی.
قوانین سختگیرانه:
1. هیچ ادعا، ویژگی یا کلمه‌ای که در سرفصل‌ها و متن نیست نیاور. هیچ‌جا قیمت یا عدد قیمت ننویس (قیمت‌ها تغییر می‌کنند).
2. focus_keyword: عبارت ۲ تا ۴ کلمه‌ای. اگر کلمه‌ی Brief با متن همخوان است، دقیقاً همان را بده.
3. meta_title: بین ۴۵ تا ۶۰ کاراکتر، عیناً شامل focus_keyword و ترجیحاً در سه کلمه‌ی اول، جذاب بدون clickbait، بدون نام برند/سایت، کامل و بدون نیمه‌کاره ماندن.
4. meta_description: بین ۱۲۰ تا ۱۶۰ کاراکتر، عیناً شامل focus_keyword (ترجیحاً در نیمه‌ی اول)، یک فایده‌ی مشخص برای خواننده (مثلاً معیارهای واقعی انتخاب که در سرفصل‌ها هست) و خلاصه‌ی صادقانه‌ی مقاله. جمله‌ها کامل باشند.
5. عبارت‌های کلی و پرکننده مثل «با ما همراه شوید»، «همین حالا»، «کلیک کنید»، «بهترین انتخاب را داشته باشید» ممنوع است؛ به‌جایش چیزی بگو که خواننده از مقاله واقعاً به دست می‌آورد.
6. excerpt: ۱ تا ۲ جمله‌ی کامل (حداکثر ۲۰۰ کاراکتر) برای کارت مقاله؛ عین meta_description نباشد.
7. slug: لاتین، حروف کوچک و خط تیره، حداکثر ۵ کلمه، بر اساس ترجمه‌ی مفهومی focus_keyword و موضوع، بدون stop word، بدون سال و عدد.
8. og_title (حداکثر ۷۰ کاراکتر) و og_description (حداکثر ۲۰۰ کاراکتر): شبیه متا ولی گفتگومحورتر و با کلمه‌بندی متفاوت؛ هر دو کامل و بدون نیمه‌کاره ماندن.
9. همه فیلدهای متنی (جز slug) فارسی.
PROMPT;

    protected function useLanguageOf(ProductArticle $article): void
    {
        $this->language = ContentLanguage::forSite($article->site);
    }

    /** برچسب ثابت فارسی → معادل زبان سایت. */
    protected function t(string $fa): string
    {
        return ContentLanguage::isPersian($this->language) ? $fa : (self::LABELS_EN[$fa] ?? $fa);
    }

    /** جداکننده‌ی «نام کوتاه؛ زاویه» در هدینگ محصول («؛» فقط برای فارسی). */
    protected function headingSeparator(): string
    {
        return ContentLanguage::isPersian($this->language) ? self::HEADING_SEPARATOR : ':';
    }

    /** مدل متن بخش‌ها: رایگان gpt-4o-mini، پرو luna — پلن در ChatModelResolver چک شده. Outline/هدینگ/متا: planningModelFor. */
    protected function textModelFor(ProductArticle $article): string
    {
        return $article->selected_model ?: ChatModelResolver::FREE_API_MODEL;
    }

    /** دستور اختیاری کاربر برای مقاله (در کنترلر پاک‌سازی شده)؛ همیشه در پیام user، نه system. */
    protected function userInstructionsBlock(ProductArticle $article): ?string
    {
        $prompt = trim((string) $article->article_user_prompt);

        return $prompt === '' ? null : "User Instructions (follow them unless they conflict with accuracy, price or product-data rules):\n".mb_substr($prompt, 0, 2000);
    }

    /** اگر پروکسی reasoning_effort را رد کرد، برای بقیه‌ی درخواست‌های همین پروسه ارسال نمی‌شود. */
    private static bool $reasoningEffortUnsupported = false;

    // ===============================================================
    //  مرحله ۱: Brief
    // ===============================================================

    public function createBrief(ProductArticle $article): array
    {
        $this->useLanguageOf($article);
        $products = $this->loadProducts($article);
        $ids = $products->keys()->map(fn ($id) => (int) $id)->all();
        $ranked = $this->rankByPrice($products);
        $total = $ranked->count();
        $style = $this->style($article);
        $categories = collect((array) $article->categories)->pluck('name')->filter()->implode('، ');

        $lines = $ranked
            ->map(fn (SiteProduct $p, $i) => "{$p->id}|{$this->name($p)}|{$this->formatPrice($p)}|price order: ".$this->priceOrderLabel($i, $total))
            ->implode("\n");

        $priced = $ranked->filter(fn ($p) => $this->price($p) > 0)->values();
        $range = $priced->isEmpty() ? 'unknown' : $this->formatPrice($priced->first()).' to '.$this->formatPrice($priced->last());

        $result = $this->chat(
            model: self::CHEAP_MODEL,
            system: str_replace('{BANNED}', implode('، ', self::ANGLE_BANNED), self::BRIEF_SYSTEM),
            user: implode("\n\n", array_filter([
                "Title: {$article->title}",
                'Style: '.self::STYLE_HINTS[$style],
                "Categories: {$categories}",
                "Price range: {$range}",
                "Products (id|name|price|price order):\n{$lines}",
                $this->userInstructionsBlock($article),
            ])),
            schemaName: 'product_article_brief',
            schema: $this->obj([
                'audience' => self::STR,
                'focus_keyword' => self::STR,
                'search_intent' => ['type' => 'string', 'enum' => ['commercial', 'transactional', 'informational']],
                'tone' => self::STR,
                'hook' => self::STR,
                'price_story' => self::STR,
                'reader_questions' => ['type' => 'array', 'items' => self::STR, 'minItems' => 4, 'maxItems' => 5],
                'buying_criteria' => ['type' => 'array', 'items' => self::STR, 'maxItems' => 5],
                'product_angles' => [
                    'type' => 'array',
                    'minItems' => count($ids),
                    'maxItems' => count($ids),
                    'items' => $this->obj(['product_id' => ['type' => 'integer', 'enum' => $ids], 'angle' => self::STR]),
                ],
            ]),
            temperature: 0.6,
            maxTokens: 1500,
        );

        // عبارت کلیدی: ۲ تا ۵ کلمه؛ در غیر این صورت از عنوان گرفته می‌شود
        $title = $this->cleanLine($article->title);
        $keyword = $this->cleanLine($result['focus_keyword'] ?? '');

        if ($keyword === '' || count(preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY)) > 5) {
            $keyword = $this->keywordFromTitle($title);
        }

        if (! $this->keywordIn($title, $keyword)) {
            Log::warning('ai.product_article_writer.brief.keyword_not_in_title', [
                'article_id' => $article->id,
                'keyword' => $keyword,
                'title' => $title,
            ]);
        }

        $result['focus_keyword'] = $keyword;
        $result['reader_questions'] = array_values(array_filter(array_map('trim', (array) ($result['reader_questions'] ?? []))));
        // تعداد کلمات هدف با کد تعیین می‌شود (AI عدد کوچک می‌داد و بخش‌ها کم‌حجم می‌شدند).
        $result['target_word_count'] = max(1000, min(3200, 700 + 290 * count($ids)));

        return $result;
    }

    // ===============================================================
    //  مرحله ۲: Assignmentها (اسکلت بخش‌ها؛ هر آیتم بعداً یک Section می‌شود)
    // ===============================================================

    /**
     * @return array<int, array{position:int, type:string, heading:string, subheadings:array, key_points:array, target_words:int, product_ids:array}>
     */
    public function createAssignments(ProductArticle $article): array
    {
        $this->useLanguageOf($article);
        $products = $this->loadProducts($article);
        $ids = $products->keys()->map(fn ($id) => (int) $id)->all();
        $brief = (array) $article->brief;
        $style = $this->style($article);
        $angles = $this->angles($brief);

        $lines = $this->rankByPrice($products)
            ->map(fn (SiteProduct $p, $i) => "{$p->id}|{$this->name($p)}|{$this->formatPrice($p)}|price order: ".$this->priceOrderLabel($i, $products->count()).'|'.($angles[$p->id] ?? ''))
            ->implode("\n");

        $briefLite = array_intersect_key($brief, array_flip(['audience', 'focus_keyword', 'search_intent', 'target_word_count', 'buying_criteria', 'reader_questions', 'price_story']));

        $result = $this->chat(
            model: ChatModelResolver::planningModelFor($article->selected_model),
            system: str_replace('{BANNED}', implode('، ', self::ANGLE_BANNED), self::OUTLINE_SYSTEM),
            user: implode("\n\n", array_filter([
                "Article Title: {$article->title}",
                'Style: '.self::STYLE_HINTS[$style],
                'Brief: '.json_encode($briefLite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                "Products (id|name|price|price order|angle):\n{$lines}",
                $this->userInstructionsBlock($article),
            ])),
            schemaName: 'product_article_outline',
            schema: $this->obj([
                'sections' => [
                    'type' => 'array',
                    'items' => $this->obj([
                        'type' => ['type' => 'string', 'enum' => ['introduction', 'comparison', 'guide', 'product', 'faq', 'conclusion']],
                        'heading' => self::STR,
                        'subheadings' => ['type' => 'array', 'items' => self::STR],
                        'key_points' => ['type' => 'array', 'items' => self::STR],
                        'target_words' => ['type' => 'integer'],
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => $ids]],
                    ]),
                ],
            ]),
            temperature: 0.6,
            maxTokens: 3000,
        );

        $assignments = $this->normalizeAssignments($result['sections'] ?? [], $products, $brief, $style);

        // هدینگ محصول‌ها یک مرحله‌ی جدا و اعتبارسنجی‌شده است (جایگزین هدینگ خام outline)
        $assignments = $this->cleanHeadings($this->applyProductHeadings($assignments, $article, $products, $brief));

        Log::info('ai.product_article_writer.create_assignments.done', [
            'article_id' => $article->id,
            'types' => array_column($assignments, 'type'),
            'headings' => array_column($assignments, 'heading'),
        ]);

        return $assignments;
    }

    /** تیتر فارسی: بدون گیومه و کلمه‌ی قرضی؛ در تیتر غیرمحصول حروف لاتین رایج هم فارسی می‌شود (نام مدل دست نمی‌خورد). */
    protected function cleanHeadings(array $assignments): array
    {
        if (! ContentLanguage::isPersian($this->language)) {
            return $assignments;
        }

        foreach ($assignments as &$a) {
            $latin = $a['type'] !== 'product';
            $a['heading'] = PersianText::normalize(PersianWritingSkill::fixHeading(str_replace(['«', '»'], '', (string) $a['heading']), $latin));

            if (isset($a['subheadings'])) {
                $a['subheadings'] = array_map(fn ($h) => PersianWritingSkill::fixHeading((string) $h, $latin), (array) $a['subheadings']);
            }
        }

        return $assignments;
    }

    /**
     * سلامت ساختار مستقل از رفتار AI: یک مقدمه/FAQ/جمع‌بندی/مقایسه، هر محصول دقیقاً یک بخش،
     * حداکثر ۲ guide، ترتیب ثابت، ترتیب قیمتی محصولات (به‌جز سبک مقایسه)، کلمپ کلمات و سئوی هدینگ‌ها.
     *
     * ترتیب نهایی: مقدمه → [guide معیارها فقط در buying_guide] → محصولات → comparison → بقیه‌ی guide ها → faq → جمع‌بندی
     */
    protected function normalizeAssignments(array $raw, Collection $products, array $brief, string $style): array
    {
        $ids = $products->keys()->map(fn ($id) => (int) $id)->all();
        $out = [];
        $seen = [];
        $guideCount = 0;

        foreach ($raw as $s) {
            $type = (string) ($s['type'] ?? '');
            $pid = (int) (((array) ($s['product_ids'] ?? []))[0] ?? 0);
            $key = $type === 'product' ? "product:{$pid}" : $type;

            $valid = match ($type) {
                'introduction', 'comparison', 'faq', 'conclusion' => ! isset($seen[$key]),
                'product' => in_array($pid, $ids, true) && ! isset($seen[$key]),
                'guide' => $guideCount < 2,
                default => false,
            };

            if (! $valid) {
                continue;
            }

            $seen[$key] = true;
            $guideCount += (int) ($type === 'guide');
            $s['product_ids'] = match ($type) {
                'product' => [$pid],
                'comparison' => $ids,
                default => [],
            };

            if (in_array($type, ['introduction', 'comparison', 'faq', 'conclusion'], true)) {
                $s['heading'] = $this->t(self::HEADINGS[$type]);
            }

            $out[] = $this->makeAssignment($type, (string) ($s['heading'] ?? ''), $s);
        }

        // تکمیل بخش‌های جاافتاده
        foreach (['introduction', 'comparison', 'faq', 'conclusion'] as $type) {
            if (! isset($seen[$type])) {
                $out[] = $this->makeAssignment($type, $this->t(self::HEADINGS[$type]), ['product_ids' => $type === 'comparison' ? $ids : []]);
            }
        }

        foreach ($products as $id => $p) {
            if (! isset($seen["product:{$id}"])) {
                $out[] = $this->makeAssignment('product', $this->compactName($p, self::MAX_HEADING_CHARS), ['product_ids' => [(int) $id]]);
            }
        }

        // اگر AI سؤال FAQ کم داد، از reader_questions برنامه‌ریزی‌شده پر می‌شود
        $questions = array_values(array_filter((array) ($brief['reader_questions'] ?? [])));

        foreach ($out as &$a) {
            if ($a['type'] === 'faq' && count($a['subheadings']) < 3 && $questions !== []) {
                $a['subheadings'] = array_slice($questions, 0, 5);
            }
        }
        unset($a);

        // ترتیب قطعی (مستقل از AI)
        $by = fn (string $t) => array_values(array_filter($out, fn ($a) => $a['type'] === $t));

        $prods = $by('product');

        // محصولات از ارزان به گران (به‌جز سبک مقایسه که ترتیب AI حفظ می‌شود)
        if ($style !== 'comparison') {
            $sortKey = fn ($a) => $this->sortPrice($products->get($a['product_ids'][0] ?? 0));
            usort($prods, fn ($a, $b) => $sortKey($a) <=> $sortKey($b));
        }

        $guides = $by('guide');
        $firstGuide = $style === 'buying_guide' ? array_slice($guides, 0, 1) : [];
        $laterGuides = array_slice($guides, count($firstGuide));

        $out = array_merge(
            $by('introduction'),
            $firstGuide,
            $prods,
            $by('comparison'),
            $laterGuides,
            $by('faq'),
            $by('conclusion'),
        );

        $out = array_map(fn ($a, $i) => $a + ['position' => $i + 1], $out, array_keys($out));

        return $this->applySeoHeadings($out, (string) ($brief['focus_keyword'] ?? ''), $products);
    }

    protected function makeAssignment(string $type, string $heading, array $extra = []): array
    {
        [$min, $max, $default] = self::WORD_RANGES[$type] ?? [150, 300, 200];
        $words = (int) ($extra['target_words'] ?? 0);
        $list = fn (string $k) => array_values(array_filter(
            (array) ($extra[$k] ?? []),
            fn ($v) => $v !== null && $v !== '' && ! $this->containsPrice((string) $v)
        ));

        return [
            'type' => $type,
            'heading' => trim($heading) !== '' ? trim($heading) : $this->t('بررسی'),
            'subheadings' => $list('subheadings'),
            'key_points' => $list('key_points'),
            'target_words' => $words > 0 ? max($min, min($max, $words)) : $default,
            'product_ids' => array_map('intval', (array) ($extra['product_ids'] ?? [])),
        ];
    }

    // ===============================================================
    //  هدینگ محصولات: نام کوتاه + زاویه‌ی کامل (هرگز بریده نمی‌شود)
    //  AI (تا ۲ تلاش، با بازخورد ایراد) → اعتبارسنجی با کد → fallback تمیز
    // ===============================================================

    protected function applyProductHeadings(array $assignments, ProductArticle $article, Collection $products, array $brief): array
    {
        $angles = $this->angles($brief);
        $accepted = [];                                    // product_id => heading
        $pending = $products->keys()->map(fn ($id) => (int) $id)->all();
        $problems = [];

        for ($attempt = 1; $attempt <= 2 && $pending !== []; $attempt++) {
            $suggested = $this->requestProductHeadings($article, $products->only($pending), $angles, $problems);
            $problems = [];
            $failed = [];

            foreach ($pending as $id) {
                $heading = $suggested[$id] ?? '';
                $reason = $this->productHeadingProblem($heading, $products->get($id), $accepted);

                if ($reason === null) {
                    $accepted[$id] = $heading;
                } else {
                    $problems[$id] = ['heading' => $heading, 'reason' => $reason];
                    $failed[] = $id;
                }
            }

            $pending = $failed;
        }

        // fallback فقط برای محصولاتی که AI دو بار هدینگ معتبر نداد
        $fallbackIds = $pending;

        foreach ($pending as $id) {
            $p = $products->get($id);
            $heading = $this->fallbackProductHeading($p, $angles[$id] ?? null);
            $taken = array_map(fn ($h) => $this->seoNormalize($h), $accepted);

            if (in_array($this->seoNormalize($heading), $taken, true)) {
                $heading = $this->cleanHeading($this->tableText((string) $p->name));
            }

            $accepted[$id] = $heading;
        }

        foreach ($assignments as &$a) {
            if ($a['type'] !== 'product') {
                continue;
            }

            $id = (int) ($a['product_ids'][0] ?? 0);

            if (isset($accepted[$id])) {
                $a['heading'] = $accepted[$id];
            }
        }
        unset($a);

        Log::info('ai.product_article_writer.product_headings.done', [
            'article_id' => $article->id,
            'fallback_product_ids' => $fallbackIds,
        ]);

        return $assignments;
    }

    /** @return array<int,string> product_id => heading (در خطا آرایه‌ی خالی؛ throw نمی‌کند) */
    protected function requestProductHeadings(ProductArticle $article, Collection $products, array $angles, array $problems = []): array
    {
        $ids = $products->keys()->map(fn ($id) => (int) $id)->all();

        $lines = $products
            ->map(fn (SiteProduct $p) => "{$p->id}|".$this->tableText((string) $p->name).'|'.($angles[$p->id] ?? ''))
            ->implode("\n");

        $feedback = $problems === [] ? '' : "\n\nPROBLEMS in previous attempt (fix them):\n"
            .collect($problems)->map(fn ($x, $id) => "{$id}: «{$x['heading']}» → {$x['reason']}")->implode("\n");

        try {
            $result = $this->chat(
                model: ChatModelResolver::planningModelFor($article->selected_model),
                system: str_replace(
                    ['{MAX}', '{BANNED}', self::HEADING_SEPARATOR],
                    [(string) (self::MAX_HEADING_CHARS - 5), implode('، ', self::ANGLE_BANNED), $this->headingSeparator()],
                    self::PRODUCT_HEADING_SYSTEM
                ),
                user: "Article Title: {$article->title}\n\nProducts (id|full name|angle):\n{$lines}{$feedback}",
                schemaName: 'product_article_product_headings',
                schema: $this->obj([
                    'headings' => [
                        'type' => 'array',
                        'items' => $this->obj([
                            'product_id' => ['type' => 'integer', 'enum' => $ids],
                            'heading' => self::STR,
                        ]),
                    ],
                ]),
                temperature: 0.5,
                maxTokens: 1500,
            );
        } catch (Throwable $e) {
            Log::warning('ai.product_article_writer.product_headings.failed', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];

        foreach ((array) ($result['headings'] ?? []) as $h) {
            $text = str_replace(['«', '»'], '', $this->cleanLine((string) ($h['heading'] ?? '')));
            $text = (string) preg_replace('/\s+[-–—]\s+/u', $this->headingSeparator().' ', $text);   // خط تیره → «؛»
            $out[(int) ($h['product_id'] ?? 0)] = $this->trimPunctuation($text);
        }

        return $out;
    }

    /** null = هدینگ معتبر؛ وگرنه دلیل رد (برای تلاش دوم هم به AI داده می‌شود). */
    protected function productHeadingProblem(string $heading, ?SiteProduct $p, array $accepted): ?string
    {
        $len = mb_strlen($heading);

        if ($p === null || $len < 8) {
            return 'خالی یا خیلی کوتاه است';
        }

        if ($len > self::MAX_HEADING_CHARS) {
            return "{$len} کاراکتر است؛ باید حداکثر ".self::MAX_HEADING_CHARS.' باشد (نام را فشرده‌تر یا زاویه را کوتاه‌تر ولی کامل بنویس)';
        }

        if (! str_contains($heading, $this->headingSeparator())) {
            return 'قالب «نام کوتاه؛ زاویه» رعایت نشده';
        }

        $angle = trim(Str::after($heading, $this->headingSeparator()));

        if (count(preg_split('/\s+/u', $angle, -1, PREG_SPLIT_NO_EMPTY)) < 2) {
            return 'زاویه‌ی کامل ندارد';
        }

        if ($this->endsWithDanglingWord($heading)) {
            return 'با حرف اضافه تمام شده و ناقص است';
        }

        foreach (self::ANGLE_BANNED as $word) {
            if (str_contains($heading, $word)) {
                return "واژه‌ی ممنوع «{$word}» دارد";
            }
        }

        if ($this->containsPrice($heading)) {
            return 'قیمت دارد';
        }

        $tokens = $this->modelTokens((string) $p->name);

        if ($tokens !== [] && ! collect($tokens)->contains(fn ($t) => mb_stripos($heading, $t) !== false)) {
            return 'شماره‌ی مدل ('.$tokens[0].') در هدینگ نیست';
        }

        $key = $this->seoNormalize($heading);

        foreach ($accepted as $other) {
            if ($this->seoNormalize($other) === $key) {
                return 'تکراری است';
            }
        }

        return null;
    }

    /** وقتی AI دو بار معتبر ننوشت: نام فشرده + زاویه (فقط اگر کامل جا شود)، وگرنه فقط نام فشرده. */
    protected function fallbackProductHeading(SiteProduct $p, ?string $angle): string
    {
        $angle = $angle !== null ? trim((string) $this->cleanAngle($angle)) : '';

        if ($angle !== '') {
            foreach ([45, 36, 28] as $limit) {
                $full = $this->compactName($p, $limit).$this->headingSeparator().' '.$angle;

                if (mb_strlen($full) <= self::MAX_HEADING_CHARS && ! $this->endsWithDanglingWord($full)) {
                    return $full;
                }
            }
        }

        return $this->compactName($p, 45);
    }

    /** نام فشرده بدون برش وسط کلمه؛ شماره‌ی مدل همیشه حفظ می‌شود. */
    protected function compactName(SiteProduct $p, int $limit): string
    {
        $name = $this->tableText((string) $p->name);
        $name = trim((string) preg_replace('/\s+مدل\s+/u', ' ', $name));

        if (mb_strlen($name) <= $limit) {
            return $name;
        }

        $token = $this->modelTokens($name)[0] ?? null;
        $reserve = $token ? mb_strlen($token) + 1 : 0;
        $out = '';

        foreach (preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $next = trim($out.' '.$w);

            if (mb_strlen($next) > $limit - $reserve) {
                break;
            }

            $out = $next;
        }

        $out = $this->trimDangling($out);

        if ($token && mb_stripos($out, $token) === false) {
            $out = trim($out.' '.$token);
        }

        return $out;
    }

    /** توکن‌های شبیه شماره‌ی مدل (حروف لاتین + عدد) مثل HG8245Q2؛ بلندترین اول. */
    protected function modelTokens(string $name): array
    {
        preg_match_all('/[A-Za-z][A-Za-z0-9\-]*\d[A-Za-z0-9\-]*|\d+[A-Za-z][A-Za-z0-9\-]*/u', $name, $m);

        $tokens = array_values(array_unique($m[0] ?? []));
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $tokens;
    }

    /**
     * برای مقاله‌های قبلی: فقط هدینگ بخش‌های محصول را دوباره می‌سازد (متن بخش‌ها دست نمی‌خورد).
     * بعد از آن assemble() و (در صورت نیاز) generateMetaFields() را دوباره صدا بزن.
     */
    public function refreshProductHeadings(ProductArticle $article): void
    {
        $this->useLanguageOf($article);
        $products = $this->loadProducts($article);
        $sections = $article->sections()->where('type', 'product')->orderBy('position')->get()->values();

        $assignments = $sections
            ->map(fn ($s) => ['type' => 'product', 'heading' => (string) $s->heading, 'product_ids' => (array) $s->product_ids])
            ->all();

        $assignments = $this->cleanHeadings($this->applyProductHeadings($assignments, $article, $products, (array) $article->brief));

        foreach ($sections as $i => $s) {
            $s->update(['heading' => $assignments[$i]['heading']]);
        }
    }

    // ===============================================================
    //  تصویر شاخص
    // ===============================================================

    /** کاربر رایگان → prompt انگلیسی؛ کاربر پولی → فارسی. alt_text همیشه فارسی است. */
    public function generateFeaturedImagePrompt(
        ProductArticle $article,
        Collection $products,
        string $imageStyle = 'product',
        ?string $modelId = null,
        ?string $featuredUserPrompt = null,
        bool $isPaidUser = true,
    ): array {
        $this->useLanguageOf($article);
        // سایت غیرفارسی: پرامپت تصویر همیشه انگلیسی (مدل‌های تصویر با انگلیسی بهترند)، alt_text به زبان سایت.
        $english = ! $isPaidUser || ! ContentLanguage::isPersian($this->language);
        $styleGuide = self::IMAGE_STYLES[$imageStyle] ?? self::IMAGE_STYLES['product'];
        $fallbackAlt = $this->fitLength($this->cleanLine($article->title) ?: $this->t('تصویر شاخص مقاله'), 125);
        $fallbackFileName = ImageFileName::from(data_get($article, 'slug'), data_get($article->brief, 'focus_keyword'))
            ?? "product-article-{$article->id}";

        // prompt اختیاری کاربر: پاک‌سازی و در صورت نیاز ترجمه
        $custom = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $featuredUserPrompt)));
        $custom = mb_substr(str_replace(['<', '>'], '', $custom), 0, 500);

        if ($custom !== '') {
            // پرامپت کاربر معمولاً فقط سبک را می‌گوید و مدل تصویر از مقاله خبر ندارد → تصویر بی‌ربط. موضوع مقاله و محصولات را ضمیمه کن.
            $productNames = $products->take(3)
                ->map(fn ($p) => $this->cleanLine((string) data_get($p, 'title', data_get($p, 'name', ''))))
                ->filter()
                ->implode('، ');
            $custom .= ' این تصویر کاور مقاله‌ای با عنوان «'.$this->cleanLine((string) $article->title).'» است'
                .($productNames !== '' ? " درباره‌ی محصول: {$productNames}" : '')
                .'؛ سوژه‌ی اصلی تصویر باید مستقیماً همین را نشان دهد.';

            return [
                'image_prompt' => $this->withNoTextSuffix($english ? $this->translatePromptToEnglish($custom, $article) : $custom, $english),
                'alt_text' => $fallbackAlt,
                'file_name' => $fallbackFileName,
            ];
        }

        $catMap = $this->categoryMap($article);
        $productsText = $products->map(function ($p, $i) use ($catMap) {
            $category = trim((string) ($catMap[data_get($p, 'category_id')] ?? ''));
            $desc = mb_substr($this->plainText((string) data_get($p, 'short_description', data_get($p, 'description', ''))), 0, 200);

            return ($i + 1).'. نام: '.trim((string) data_get($p, 'title', data_get($p, 'name', '')))
                .($category !== '' ? " | دسته: {$category}" : '')
                .($desc !== '' ? " | توضیح: {$desc}" : '');
        })->implode("\n");

        $count = $products->count();
        $composition = match (true) {
            $count === 0 => 'اطلاعات محصول در دسترس نیست؛ بر اساس عنوان مقاله یک سوژه‌ی مرتبط انتخاب کن.',
            $count === 1 => 'مقاله درباره‌ی یک محصول است: یک hero shot دقیق از همان محصول، سوژه‌ی اصلی و واضح در مرکز توجه.',
            default => "مقاله درباره‌ی {$count} محصول است: همه‌ی محصولات را در یک قاب واحد، مرتب و متعادل کنار هم بچین (flat-lay یا چیدمان استودیویی) به‌طوری که هر محصول قابل تشخیص باشد و هیچ‌کدام حذف نشود.",
        };

        $language = $english
            ? 'image_prompt حتماً و فقط به «انگلیسی» نوشته شود. نام فارسی محصول را transliterate نکن؛ محصول را به انگلیسیِ طبیعی و بصری توصیف کن. فقط alt_text فارسی باشد.'
            : 'image_prompt فارسی و روان باشد، توصیف بصری طبیعی (نه ترجمه‌ی تحت‌اللفظی).';

        $result = $this->chat(
            model: self::CHEAP_MODEL,
            system: <<<PROMPT
تو prompt engineer حرفه‌ای مدل‌های تولید تصویر هستی. دقیقاً یک پرامپت برای «تصویر شاخص (کاور)» یک مقاله‌ی فارسی محصول‌محور بنویس.
قوانین:
1. {$language}
2. سبک الزامی: {$styleGuide}
3. ترکیب‌بندی: {$composition}
4. حداکثر ۲ جمله در یک پاراگراف بدون markdown: سوژه، چیدمان، پس‌زمینه، حال‌وهوا، نورپردازی، زاویه‌ی دوربین.
5. ظاهر محصول فقط از روی نام و توضیحش؛ رنگ یا جزئیات ساختگی اضافه نکن.
6. هیچ لوگو، واترمارک، نام برند یا نوشته‌ای در تصویر نباشد و به متن/عدد/برچسب اشاره نکن.
7. کاملاً family-friendly: فقط خود محصول روی پس‌زمینه‌ی ساده؛ هیچ انسان، بدن، دست یا صورتی در قاب نباشد.
alt_text: فارسی، توصیفی و طبیعی، حداکثر ۱۱۰ کاراکتر، کامل و بدون نیمه‌کاره ماندن، با نام محصول/موضوع اصلی؛ اگر طبیعی بود focus_keyword از Brief را عیناً بیاور (بدون keyword stuffing) و با کلمه‌ی «تصویر» یا «عکس» شروع نکن.
file_name: نام فایل سئو-فرندلی فقط با حروف کوچک انگلیسی، عدد و خط تیره (kebab-case)، ۳ تا ۶ کلمه، بدون پسوند، ترجمه‌ی مفهومی نام محصول/موضوع اصلی (نه transliteration فارسی). مثال: stainless-steel-espresso-machine
PROMPT,
            user: implode("\n\n", array_filter([
                "Article Title: {$article->title}",
                'Content Style: '.(string) $article->content_style,
                $productsText !== '' ? "Products:\n{$productsText}" : null,
                'Brief: '.json_encode(array_intersect_key((array) $article->brief, array_flip(['focus_keyword', 'audience'])), JSON_UNESCAPED_UNICODE),
            ])),
            schemaName: 'product_article_featured_image_prompt',
            schema: $this->obj(['image_prompt' => self::STR, 'alt_text' => self::STR, 'file_name' => self::STR]),
            temperature: 0.6,
            maxTokens: 600,
        );

        Log::info('ai.product_article_writer.featured_image_prompt.done', [
            'article_id' => $article->id,
            'language' => $english ? 'en' : 'fa',
            'model_id' => $modelId,
            'products_count' => $count,
        ]);

        $alt = $this->fitLength($this->cleanLine($result['alt_text'] ?? ''), 125);

        return [
            'image_prompt' => $this->withNoTextSuffix(trim((string) ($result['image_prompt'] ?? '')), $english),
            'alt_text' => $alt !== '' ? $alt : $fallbackAlt,
            'file_name' => ImageFileName::from($result['file_name'] ?? null) ?? $fallbackFileName,
        ];
    }

    /** جمله‌ی «بدون متن» را (فقط برای prompt انگلیسی و اگر نبود) به انتها اضافه می‌کند. */
    protected function withNoTextSuffix(string $prompt, bool $english): string
    {
        return ($prompt === '' || ! $english || str_contains(mb_strtolower($prompt), 'no text, no letters'))
            ? $prompt
            : $prompt.' '.self::NO_TEXT_SUFFIX_EN;
    }

    /** ترجمه‌ی prompt دستی به انگلیسی؛ در صورت هر خطایی متن اصلی برمی‌گردد (throw نمی‌کند). */
    protected function translatePromptToEnglish(string $text, ProductArticle $article): string
    {
        if (! preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return $text;
        }

        try {
            $result = $this->chat(
                model: self::CHEAP_MODEL,
                system: 'Translate the following image-generation prompt into fluent, natural English. Keep the meaning exactly; do not add or remove details. Return only JSON.',
                user: $text,
                schemaName: 'image_prompt_translation',
                schema: $this->obj(['translation' => self::STR]),
                temperature: 0.2,
                maxTokens: 400,
                localize: false,
            );

            return trim((string) ($result['translation'] ?? '')) ?: $text;
        } catch (Throwable $e) {
            Log::warning('ai.product_article_writer.translate_failed', ['article_id' => $article->id, 'error' => $e->getMessage()]);

            return $text;
        }
    }

    // ===============================================================
    //  مرحله ۳: تولید متن یک Section
    // ===============================================================

    public function generateSection(ProductArticleSection $section, ?string $model = null): string
    {
        $article = $section->article;
        $this->useLanguageOf($article);
        $brief = (array) $article->brief;
        $products = $this->loadProducts($article);
        $type = $section->type;
        $model = $this->textModelFor($article); // پارامتر $model از job دیگر اعمال نمی‌شود
        $ranked = $this->rankByPrice($products);
        $angles = $this->angles($brief);

        $outline = $article->sections()->orderBy('position')->get(['position', 'type', 'heading'])
            ->map(fn ($s) => "{$s->position}. [{$s->type}] {$s->heading}")
            ->implode("\n");

        $previous = $article->sections()
            ->where('status', ProductArticleSection::STATUS_GENERATED)
            ->where('position', '<', $section->position)
            ->orderByDesc('position')
            ->limit(2)
            ->get(['heading', 'content', 'position'])
            ->sortBy('position')
            ->map(fn ($s) => "### {$s->heading}\n".$this->tail((string) $s->content, 300))
            ->implode("\n\n");

        // داده‌ی هر بخش: فقط آنچه همان بخش لازم دارد (جلوی تکرار اطلاعات و هدررفت توکن)
        $links = [];
        $product = null;
        $extras = [];
        $categoryLinks = $this->categoryLinks($article, $type);

        if ($type === 'product') {
            $product = $products->get((int) ($section->product_ids[0] ?? 0))
                ?? throw new RuntimeException("The product for section \"{$section->heading}\" was not found.");

            $facts = $this->withoutPriceLines($this->productFacts($product, $this->categoryMap($article)));

            $block = "PRODUCT DATA (the only source of product-specific facts; it intentionally contains no price):\n".$facts
                ."\nangle (editorial direction; rephrase in your own words): ".($angles[$product->id] ?? '')
                ."\n".$this->pricePosition($product, $ranked)
                ."\nOPENING MOVE: ".self::OPENING_MOVES[((int) $section->position) % count(self::OPENING_MOVES)]
                ."\nENDING: finish on a specific practical note (an occasion, a pairing, something to check before buying). Not a summary, not a slogan, not a call to action.";

            if ($product->url) {
                $links["PRODUCT_{$product->id}"] = $product->url;
                $block .= "\nLINK: inside the first paragraph, in a sentence where it reads naturally, link the product exactly once as [short natural anchor](PRODUCT_{$product->id}). Anchor = 2 to 5 natural ".ContentLanguage::name($this->language)." words naming the product (brand + pattern + model number). Write the placeholder verbatim.";
            }
        } else {
            $count = $ranked->count();
            $short = fn (SiteProduct $p) => $this->name($p, 60);

            $block = match ($type) {
                'introduction' => "SELECTION: {$count} products from this store. Do NOT name any product or brand, no prices, no verdict.",
                'faq' => "SELECTION: {$count} products from this store. Do not name or price any product.",
                'comparison' => "PRODUCTS, cheapest first (names only, no prices):\n"
                    .$ranked->map($short)->implode("\n")
                    ."\nA table with all products already sits above your text. Do not repeat it. Never write any price or amount.",
                'guide' => "PRODUCTS (names only):\n".$ranked->map($short)->implode("\n")."\nNo prices, no links in this section.",
                default => "PRODUCTS (name | angle):\n" // conclusion
                    .$ranked->map(fn (SiteProduct $p) => $short($p).' | '.($angles[$p->id] ?? ''))->implode("\n")
                    ."\nNo prices, no product links.",
            };

            foreach ($categoryLinks as $c) {
                $links[$c['key']] = $c['url'];
                $block .= "\nLINK: link the store's category page exactly once"
                    .($type === 'introduction' ? ' (within the first two paragraphs)' : '')
                    ." as [anchor]({$c['key']}). Category: «{$c['name']}». Anchor = 2 to 4 natural ".ContentLanguage::name($this->language)." words naming this product type (the category name or a natural form of it), never «اینجا» or «کلیک کنید». Write the placeholder verbatim.";
            }

            if ($type === 'introduction' && ! empty($brief['hook'])) {
                $extras[] = 'HOOK (adapt it in your own words, do not copy mechanically): '.$brief['hook'];
            }

            if ($type === 'comparison' && ! empty($brief['price_story']) && ! $this->containsPrice((string) $brief['price_story'])) {
                $extras[] = 'PRICE STORY (from the brief): '.$brief['price_story'];
            }
        }

        $subheadings = array_values(array_filter((array) $section->subheadings));
        $keyPoints = array_values(array_filter((array) $section->key_points));
        $briefLite = array_intersect_key($brief, array_flip(['audience', 'focus_keyword', 'search_intent', 'tone', 'buying_criteria']));

        // ترتیب عمداً حفظ شده: بخش‌های مشترک بین سکشن‌ها اول، تا prompt caching فعال شود.
        $user = implode("\n\n", array_filter([
            "TITLE: {$article->title}",
            'BRIEF: '.json_encode($briefLite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "OUTLINE:\n{$outline}",
            "PREVIOUS SECTIONS (endings, for flow only — do not repeat):\n".($previous ?: 'none yet'),
            '=== SECTION TO WRITE ===',
            "TYPE: {$type}\nHEADING (do not write it): {$section->heading}",
            $block,
            $extras ? implode("\n", $extras) : null,
            $subheadings ? "SUBHEADINGS (H3, in order):\n- ".implode("\n- ", $subheadings) : null,
            $keyPoints ? "KEY POINTS (cover each specifically, in your own words):\n- ".implode("\n- ", $keyPoints) : null,
            "TARGET WORDS: {$section->target_words} (±15%)",
            self::TYPE_RULES[$type] ?? 'Main body section: practical and specific.',
            $this->userInstructionsBlock($article),
        ]));

        $system = strtr(self::SECTION_SYSTEM, [
            '{CLICHES}' => implode('، ', array_map(fn ($c) => "«{$c}»", PersianText::PROMPT_CLICHES)),
            '{PLAIN_LANGUAGE}' => ContentLanguage::isPersian($this->language) ? PersianWritingSkill::writerPrompt() : PersianText::PLAIN_LANGUAGE_RULES,
        ]);
        $maxTokens = (int) min(8000, max($type === 'faq' ? 3000 : 2500, ((int) $section->target_words) * 4 + 1500));

        $generate = fn (string $prompt): string => $this->sanitizeContent((string) ($this->chat(
            model: $model,
            system: $system,
            user: $prompt,
            schemaName: 'product_article_section',
            schema: $this->obj(['content' => self::STR]),
            temperature: 0.8,
            timeout: 150,
            maxTokens: $maxTokens,
        )['content'] ?? ''));

        $content = $generate($user);

        // FAQ: هر سؤال باید جواب کامل داشته باشد؛ در غیر این صورت یک بار با تذکر دوباره تولید می‌شود
        if ($type === 'faq' && $this->faqIncomplete($content, $subheadings)) {
            $retry = $generate($user."\n\nIMPORTANT: your previous attempt left some questions without an answer or stopped early. Write EVERY question as an H3 followed by its own complete 2-4 sentence answer, including the last one.");

            if (! $this->faqIncomplete($retry, $subheadings) || mb_strlen($retry) > mb_strlen($content)) {
                $content = $retry;
            }
        }

        if (mb_strlen($content) < 100) {
            throw new RuntimeException("The generated text for section \"{$section->heading}\" is too short.");
        }

        // ---- کنترل کیفیت با کد → حداکثر یک بار بازنویسی هدفمند ----
        $keyword = trim((string) ($brief['focus_keyword'] ?? ''));
        $allowedLatin = $this->allowedLatin($article, $products);
        $issues = array_merge($this->findCliches($content), $this->findFormalWords($content), $this->findLatinWords($content, $allowedLatin), $this->findMetaLeaks($content));
        $priceLeak = $this->hasPriceLeak($type, $content);

        if ($priceLeak) {
            $issues[] = 'هر عدد قیمت (عدد همراه با تومان/ریال/دلار)؛ در این بخش ذکر قیمت ممنوع است، جمله را بدون قیمت بازنویسی کن';
        }

        if ($type === 'introduction' && $keyword !== '' && ! $this->keywordInOpening($content, $keyword)) {
            $issues[] = "عبارت کلیدی «{$keyword}» عیناً در ابتدای مقدمه نیامده؛ جمله‌ی اول را طوری بازنویسی کن که این عبارت را عیناً و طبیعی داشته باشد";
        }

        $kwLimit = self::KEYWORD_MAX_PER_SECTION[$type] ?? self::KEYWORD_MAX_PER_SECTION['default'];
        $kwCount = $keyword !== '' ? $this->keywordCount($content, $keyword) : 0;

        if ($kwCount > $kwLimit) {
            $issues[] = "عبارت کلیدی «{$keyword}» {$kwCount} بار آمده (سقف {$kwLimit})؛ در جمله‌های اضافه با مترادف یا شکل کوتاه‌تر جایگزین کن";
        }

        if ($issues) {
            $content = $this->polishLines(
                $content,
                $issues,
                fn (string $line) => $this->findCliches($line) !== []
                    || $this->findFormalWords($line) !== []
                    || $this->findLatinWords($line, $allowedLatin) !== []
                    || $this->findMetaLeaks($line) !== []
                    || ($priceLeak && $this->containsPrice($line))
                    || ($kwCount > $kwLimit && $this->keywordCount($line, $keyword) > 0),
                $type === 'introduction' && $keyword !== '' && ! $this->keywordInOpening($content, $keyword),
                ContentLanguage::isPersian($this->language),
            );
        }

        // شبکه‌ی ایمنی نهایی: اگر با وجود prompt و بازنویسی هنوز قیمت مانده بود، جمله‌ی دارای قیمت با کد حذف می‌شود
        $priceStripped = false;

        if ($this->containsPrice($content)) {
            $content = $this->stripPriceSentences($content);
            $priceStripped = true;
        }

        // placeholder → URL امن؛ لینک غیرمجاز/تکراری → فقط متن؛ placeholder لخت → حذف
        $content = $this->applyLinks($content, $links);
        $content = $this->linkProductMentions($content, $products, $product?->id);

        // شبکه ایمنی: لینک محصول و لینک دسته‌ها همیشه در متن می‌آید
        if ($product?->url && ! Str::contains($content, $this->safeUrl($product->url))) {
            $content .= "\n\nقیمت و موجودی روز این مدل را در [صفحه‌ی محصول](".$this->safeUrl($product->url).') ببینید.';
        }

        foreach ($categoryLinks as $c) {
            $safe = $this->safeUrl($c['url']);
            $content = $this->linkFirstMention($content, $c['name'], $c['url']);

            if (! Str::contains($content, $safe)) {
                $name = trim(str_replace(['[', ']'], '', $c['name']));
                $content .= "\n\nمدل‌های موجود در دسته‌ی [{$name}]({$safe}) را هم ببینید.";
            }
        }

        Log::info('ai.product_article_writer.generate_section.done', [
            'section_id' => $section->id,
            'type' => $type,
            'content_length' => mb_strlen($content),
            'issues_found' => $issues,
            'price_sentences_stripped' => $priceStripped,
            'keyword_count_before_polish' => $kwCount,
        ]);

        return $content;
    }

    /** در بخش‌هایی که قیمت ممنوع است (الان همه‌ی بخش‌ها)، عدد همراه با واحد پول را تشخیص می‌دهد. */
    protected function hasPriceLeak(string $type, string $content): bool
    {
        return in_array($type, self::NO_PRICE_TYPES, true) && $this->containsPrice($content);
    }

    /** خطوطی از داده‌ی محصول که قیمت دارند (برچسب قیمت یا عدد+واحد پول) به مدل داده نمی‌شوند. */
    protected function withoutPriceLines(string $facts): string
    {
        $lines = preg_split('/\R/u', $facts) ?: [];

        return implode("\n", array_filter(
            $lines,
            fn (string $l) => ! preg_match('/^\s*[-•*]?\s*(قیمت|price)\b/iu', $l) && ! $this->containsPrice($l)
        ));
    }

    /** جمله‌های دارای قیمت را حذف می‌کند؛ هدینگ‌ها دست‌نخورده می‌مانند. */
    protected function stripPriceSentences(string $content): string
    {
        $out = [];

        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            if ($line === '' || preg_match('/^\s*#{1,6}\s/u', $line) || ! $this->containsPrice($line)) {
                $out[] = $line;

                continue;
            }

            $kept = array_filter(
                preg_split('/(?<=[.!?؟۔])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                fn (string $s) => ! $this->containsPrice($s)
            );

            if ($kept !== []) {
                $out[] = implode(' ', $kept);
            }
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
    }

    /**
     * FAQ ناقص: تعداد سؤال کمتر از انتظار، یا حداقل یک سؤال بدون جواب واقعی.
     */
    protected function faqIncomplete(string $content, array $subheadings): bool
    {
        preg_match_all('/^###[ \t]+(.+?)[ \t]*\n(.*?)(?=^###[ \t]|\z)/msu', str_replace("\r\n", "\n", $content), $pairs, PREG_SET_ORDER);

        $expected = $subheadings !== [] ? count($subheadings) : 3;

        if (count($pairs) < $expected) {
            return true;
        }

        foreach ($pairs as [, , $answer]) {
            if (mb_strlen($this->plainText($answer)) < 40) {
                return true;
            }
        }

        return false;
    }

    /** نشت اطلاعات داخلی prompt (رتبه‌ی قیمتی، «جدول/لیست ما») به متن نهایی. */
    protected function findMetaLeaks(string $content): array
    {
        $issues = [];

        if (preg_match('/رتبه\s*[\d۰-۹]+\s*(?:از\s*[\d۰-۹]+)?|price\s*rank/iu', $content)) {
            $issues[] = 'اشاره به «رتبه‌ی قیمتی» (مثل رتبه ۱ از ۵)؛ عدد رتبه را حذف کن و جایگاه را با کلمات ساده بگو (ارزان‌ترین گزینه، یک پله بالاتر از مدل پایه، بالاترین مدل)';
        }

        if (preg_match('/(?:جدول|لیست|فهرست)\s+ما\b/u', $content)) {
            $issues[] = 'عبارت «جدول/لیست ما» که درباره‌ی خود مقاله حرف می‌زند؛ حذف یا بدون اشاره به لیست بازنویسی کن';
        }

        return $issues;
    }

    /** اولین اشاره‌ی متنیِ $text را (خارج از لینک‌های موجود) به لینک تبدیل می‌کند. */
    protected function linkFirstMention(string $content, string $text, string $url): string
    {
        $text = trim($text);

        if ($text === '' || trim($url) === '') {
            return $content;
        }

        $parts = preg_split('/(\[[^\]]*\]\([^)]*\))/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 || ($pos = mb_strpos($part, $text)) === false) {
                continue;
            }

            $parts[$i] = mb_substr($part, 0, $pos)."[{$text}](".$this->safeUrl($url).')'.mb_substr($part, $pos + mb_strlen($text));
            break;
        }

        return implode('', $parts);
    }

    /** نام محصولاتی که در متن آمده، رندوم ۲ تا همه، بدون AI لینک می‌شود (هر محصول حداکثر یک بار). */
    protected function linkProductMentions(string $content, Collection $products, ?int $skipId): string
    {
        $eligible = $products
            ->filter(fn ($p) => $p->id !== $skipId && trim((string) $p->url) !== '' && ! Str::contains($content, $this->safeUrl($p->url)))
            ->shuffle();

        $limit = random_int(min(2, $eligible->count()), $eligible->count());
        $done = 0;

        foreach ($eligible as $p) {
            if ($done >= $limit) {
                break;
            }

            foreach (array_unique([$this->name($p, 60), (string) $p->name]) as $name) {
                $linked = $this->linkFirstMention($content, $name, $p->url);

                if ($linked !== $content) {
                    $content = $linked;
                    $done++;
                    break;
                }
            }
        }

        return $content;
    }

    /** پاک‌سازی خروجی مدل: حروف عربی، H1/H2 و هر جدول/HTML ابتدای متن (جدول را فقط assemble می‌گذارد). */
    protected function sanitizeContent(string $content): string
    {
        $content = $this->stripLegacyTable(str_replace(['ي', 'ك'], ['ی', 'ک'], $content));
        $content = (string) preg_replace('/\A\s*#{1,2}[ \t]+[^\n]*\n+/u', '', $content);   // H1/H2 ابتدای بخش
        $content = (string) preg_replace('/^#{1,2}[ \t]+/mu', '### ', $content);          // H1/H2 باقی‌مانده → H3

        if (ContentLanguage::isPersian($this->language)) {
            $content = PersianText::normalize($content);
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $content));
    }

    /** @return array<int, string> */
    protected function findCliches(string $content): array
    {
        return ContentLanguage::isPersian($this->language) ? PersianText::findPhrases($content, self::CLICHES) : [];
    }

    /** کلمه‌ی انگلیسی در متن فارسی؛ کلمه‌های لاتین نام محصولات (مدل/سری) و نام سایت مجازند. */
    protected function findLatinWords(string $content, array $allowed): array
    {
        return ContentLanguage::isPersian($this->language) ? PersianText::latinWordIssues($content, $allowed) : [];
    }

    /** @return array<int, string> */
    protected function allowedLatin(ProductArticle $article, Collection $products): array
    {
        $names = $products->map(fn (SiteProduct $p) => (string) $p->name)->push((string) $article->site?->brandName())->implode(' ');

        return preg_split('/[^A-Za-z0-9\-]+/', $names, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return array<int, string> */
    protected function findFormalWords(string $content): array
    {
        // کلمه‌ی سنگین و جمله‌ی بیش از حد بلند
        return ContentLanguage::isPersian($this->language) ? PersianWritingSkill::issues($content) : [];
    }

    // ===============================================================
    //  مرحله ۴: مقاله نهایی
    // ===============================================================

    public function assemble(ProductArticle $article): string
    {
        $this->useLanguageOf($article);
        $sections = $article->sections()->orderBy('position')->get();

        if ($sections->isEmpty() || $sections->contains(fn ($s) => $s->status !== ProductArticleSection::STATUS_GENERATED)) {
            throw new RuntimeException('Not all article sections have been generated yet.');
        }

        $products = $this->loadProducts($article);
        $angles = $this->angles((array) $article->brief);
        $jump = $this->jumpLinks($sections, $products);

        return $sections->map(function ($s) use ($products, $angles, $jump) {
            $body = $this->stripLegacyTable((string) $s->content);

            if ($s->type === 'comparison') {
                $body = $this->productsTable($products, $angles)."\n\n".$body;
            }

            $anchor = $this->sectionAnchor($s);
            $out = ($anchor !== '' ? "{$anchor}\n\n" : '')."## {$s->heading}\n\n".$body;

            // فهرست دسترسی سریع (بدون قیمت) درست بعد از مقدمه
            if ($s->type === 'introduction' && $jump !== '') {
                $out .= "\n\n".$jump;
            }

            return $out;
        })->implode("\n\n");
    }

    /** لنگر HTML قبل از هدینگ بخش‌های product و comparison برای jump link. */
    protected function sectionAnchor($section): string
    {
        return match ($section->type) {
            'product' => '<span id="product-'.(int) ($section->product_ids[0] ?? 0).'"></span>',
            'comparison' => '<span id="quick-comparison"></span>',
            default => '',
        };
    }

    /** فهرست لینک‌دار نام کوتاه محصولات (بدون قیمت)؛ زیر ۳ محصول لازم نیست. */
    protected function jumpLinks(Collection $sections, Collection $products): string
    {
        $items = [];

        foreach ($sections as $s) {
            if ($s->type !== 'product') {
                continue;
            }

            $pid = (int) ($s->product_ids[0] ?? 0);
            $p = $products->get($pid);

            if ($p) {
                // فقط نام کوتاه (قبل از «؛») در فهرست می‌آید
                $label = trim(str_replace(['[', ']'], '', Str::before((string) $s->heading, $this->headingSeparator())));
                $label = $label !== '' ? $label : trim(str_replace(['[', ']'], '', $this->name($p, 60)));
                $items[] = "- [{$label}](#product-{$pid})";
            }
        }

        if (count($items) < 3) {
            return '';
        }

        if ($sections->contains(fn ($s) => $s->type === 'comparison')) {
            $items[] = '- ['.$this->t('جدول مقایسه سریع').'](#quick-comparison)';
        }

        return '**'.$this->t('محصولات این مقاله:')."**\n\n".implode("\n", $items);
    }

    /**
     * جدولِ چسبیده به ابتدای content (نسخه‌های قدیمی کد) را حذف می‌کند، چه داخل <div> باشد چه بدون آن.
     * جدول فقط در assemble() ساخته می‌شود و نباید داخل content ذخیره شود.
     */
    public function stripLegacyTable(string $content): string
    {
        return trim((string) preg_replace('#\A\s*(?:<div\b[^>]*>\s*)?<table\b.*?</table>\s*(?:</div>\s*)?#isu', '', $content));
    }

    /**
     * جدول مقایسه‌ی محصولات (نام لینک‌دار، جایگاه؛ ستون قیمت فقط اگر TABLE_SHOW_PRICE=true).
     * قیمت‌ها پیش‌فرض در جدول نمی‌آیند، چون کارت محصول سایت قیمت را نشان می‌دهد.
     * این متد نسخه‌ی trait را (در صورت وجود) override می‌کند.
     */
    protected function productsTable(Collection $products, array $angles = []): string
    {
        $ranked = $this->rankByPrice($products);
        $prices = $ranked->map(fn ($p) => $this->price($p))->filter()->values();
        $showPrice = static::TABLE_SHOW_PRICE;
        $head = array_map(fn ($h) => $this->t($h), $showPrice ? ['محصول', 'قیمت', 'مشخصات'] : ['محصول', 'مشخصات']);

        $rows = $ranked->map(function (SiteProduct $p) use ($angles, $prices) {
            $note = $this->cleanAngle($angles[$p->id] ?? null) ?? $this->priceTierNote($this->price($p), $prices);

            return [
                'name' => $this->tableText((string) $p->name),
                'url' => trim((string) $p->url),
                'price' => $this->tableText((string) $this->formatPrice($p)),
                'note' => $this->tableText((string) $note),
            ];
        });

        if (static::TABLE_AS_HTML) {
            $tr = fn (array $cells, string $tag) => '<tr>'.implode('', array_map(fn ($c) => "<{$tag}>{$c}</{$tag}>", $cells)).'</tr>';
            $body = $rows->map(function ($r) use ($tr, $showPrice) {
                $name = $r['url'] !== ''
                    ? '<a href="'.e($this->safeUrl($r['url'])).'">'.e($r['name']).'</a>'
                    : e($r['name']);

                return $tr($showPrice ? [$name, e($r['price']), e($r['note'])] : [$name, e($r['note'])], 'td');
            })->implode("\n");

            return "<div style=\"overflow-x:auto\">\n<table>\n<thead>\n{$tr($head, 'th')}\n</thead>\n<tbody>\n{$body}\n</tbody>\n</table>\n</div>";
        }

        $lines = ['| '.implode(' | ', $head).' |', '|'.str_repeat(' --- |', count($head))];

        foreach ($rows as $r) {
            // کروشه داخل anchor لینک Markdown را می‌شکند
            $label = trim(str_replace(['[', ']'], '', $r['name']));
            $name = ($r['url'] !== '' && $label !== '')
                ? "[{$label}](".$this->safeUrl($r['url']).')'
                : $label;

            $price = $r['price'] !== '' ? $r['price'] : '—';
            $note = $r['note'] !== '' ? $r['note'] : '—';

            $lines[] = $showPrice ? "| {$name} | {$price} | {$note} |" : "| {$name} | {$note} |";
        }

        return implode("\n", $lines);
    }

    // ===============================================================
    //  مرحله ۵: متا و سئوی صفحه
    // ===============================================================

    /** متا فقط از متن نهایی ساخته می‌شود؛ faq_schema و product_schema با کد (بدون AI). $article->content باید مقاله‌ی کامل باشد. */
    public function generateMetaFields(ProductArticle $article): array
    {
        $this->useLanguageOf($article);
        $content = (string) $article->content;
        $plain = $this->plainText($content);
        $intro = $this->introText($content);
        $title = $this->cleanLine($article->title);
        $planned = trim((string) (((array) $article->brief)['focus_keyword'] ?? ''));
        $headings = $this->extractHeadings($content);

        $result = $this->chat(
            model: ChatModelResolver::planningModelFor($article->selected_model),
            system: self::META_SYSTEM,
            user: implode("\n\n", array_filter([
                "Title: {$article->title}",
                'Focus Keyword برنامه‌ریزی‌شده در Brief (در صورت همخوانی دقیقاً همین را بده): '.($planned !== '' ? $planned : 'مشخص نشده'),
                $headings !== '' ? "--- سرفصل‌های مقاله ---\n{$headings}" : null,
                "--- ابتدای متن نهایی ---\n".mb_substr($plain, 0, 1500),
            ])),
            schemaName: 'product_article_meta_fields',
            schema: $this->obj(array_fill_keys(
                ['focus_keyword', 'meta_title', 'meta_description', 'excerpt', 'slug', 'og_title', 'og_description'],
                self::STR
            )),
            temperature: 0.4,
            timeout: 150,
            maxTokens: 1200,
            reasoningEffort: 'minimal',
        );

        // ---- focus keyword: کلمه‌ی Brief (که هدینگ‌ها و مقدمه با آن ساخته شده‌اند) اگر در متن هست قطعی است ----
        $keyword = $this->cleanLine($result['focus_keyword'] ?? '');
        $kwWords = $keyword === '' ? 0 : count(preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY));

        if ($planned !== '' && $this->keywordIn($plain, $planned)) {
            $keyword = $planned;
        } elseif ($keyword === '' || $kwWords > 5 || ! $this->keywordIn($plain, $keyword)) {
            $keyword = $planned !== '' ? $planned : $this->keywordFromTitle($title);
        }

        // اگر AI با وجود ممنوعیت قیمت نوشت، از جایگزین بدون قیمت استفاده می‌شود
        $noPrice = fn (string $value, string $fallback) => $this->containsPrice($value) ? $fallback : $value;

        // ---- meta title / description: عبارت کلیدی عیناً و ترجیحاً ابتدای متن ----
        $metaTitle = $noPrice($this->cleanLine($result['meta_title'] ?? ''), $title) ?: $title;
        $metaTitle = $this->ensureKeywordAtStart($metaTitle, $keyword, 60);

        $excerpt = $noPrice($this->cleanLine($result['excerpt'] ?? ''), $intro) ?: $intro;
        $excerpt = $this->fitLength($excerpt, 200);

        $metaDesc = $noPrice($this->cleanLine($result['meta_description'] ?? ''), $intro) ?: $intro;
        $metaDesc = $this->ensureKeywordAtStart($metaDesc, $keyword, 160);

        // ---- Open Graph ----
        $ogTitle = $this->fitLength($noPrice($this->cleanLine($result['og_title'] ?? ''), $metaTitle) ?: $metaTitle, 70);
        $ogDesc = $this->fitLength($noPrice($this->cleanLine($result['og_description'] ?? ''), $metaDesc) ?: $metaDesc, 200);

        // ---- slug ----
        $slug = $this->slugify((string) ($result['slug'] ?? ''))
            ?: $this->slugify($keyword)
            ?: $this->slugify($title);

        $meta = [
            'focus_keyword' => $keyword,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'excerpt' => $excerpt,
            'og_title' => $ogTitle,
            'og_description' => $ogDesc,
            'slug' => $slug ?: null,
            'faq_schema' => $this->buildFaqSchema($content),
            'product_schema' => $this->buildProductSchema($article),
        ];

        $this->logSeoReport($article, $meta, $content);

        return $meta;
    }

    // ===============================================================
    //  Helperها
    // ===============================================================

    /** محصولات انتخاب‌شده‌ی مقاله به همان ترتیب ذخیره‌شده. */
    protected function loadProducts(ProductArticle $article): Collection
    {
        $ids = array_map('intval', (array) $article->product_ids);
        $rows = SiteProduct::where('site_id', $article->site_id)->whereIn('id', $ids)->get()->keyBy('id');
        $ordered = collect($ids)->filter(fn ($id) => $rows->has($id))->mapWithKeys(fn ($id) => [$id => $rows[$id]]);

        if ($ordered->isEmpty()) {
            throw new RuntimeException('This article\'s products were not found in the database.');
        }

        return $ordered;
    }

    protected function style(ProductArticle $article): string
    {
        return array_key_exists((string) $article->content_style, self::STYLE_HINTS) ? $article->content_style : 'buying_guide';
    }

    /** product_id => angle */
    protected function angles(array $brief): array
    {
        return collect($brief['product_angles'] ?? [])
            ->pluck('angle', 'product_id')
            ->reject(fn ($angle) => $this->containsPrice((string) $angle))
            ->all();
    }

    /**
     * لینک دسته‌ها برای هر نوع بخش: دسته‌ی هدف (اولین آیتم categories) در مقدمه و جمع‌بندی،
     * بقیه‌ی دسته‌ها (مثل طرف دوم مقایسه) در comparison. URL همیشه از دیتابیس خوانده می‌شود.
     * انتخاب دسته‌ی هدف قبل از فیلتر URL انجام می‌شود، تا نبودن URL دسته‌ی هدف باعث جابه‌جایی نقش دسته‌ها نشود.
     *
     * @return array<int, array{key:string,url:string,name:string}>
     */
    protected function categoryLinks(ProductArticle $article, string $type): array
    {
        $ids = collect((array) $article->categories)->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty() || ! in_array($type, ['introduction', 'comparison', 'conclusion'], true)) {
            return [];
        }

        $rows = SiteProductCategory::where('site_id', $article->site_id)->whereIn('id', $ids)->get(['id', 'name', 'url'])->keyBy('id');

        // فقط وجود دسته در دیتابیس؛ فیلتر URL بعد از انتخاب نقش‌ها
        $cats = $ids->map(fn ($id) => $rows->get($id))->filter()->values();

        $selected = $type === 'comparison' ? $cats->slice(1) : $cats->take(1);

        return $selected
            ->filter(fn ($c) => trim((string) $c->url) !== '')
            ->unique('url')
            ->map(fn ($c) => [
                'key' => "CATEGORY_{$c->id}",
                'url' => trim((string) $c->url),
                'name' => trim(html_entity_decode((string) $c->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ])
            ->values()
            ->all();
    }

    /** URL را برای قرارگرفتن داخل لینک Markdown / href امن می‌کند (فاصله، پرانتز، کروشه، کوتیشن). */
    protected function safeUrl(string $url): string
    {
        return str_replace(
            [' ', '(', ')', '[', ']', '<', '>', '"', "'"],
            ['%20', '%28', '%29', '%5B', '%5D', '%3C', '%3E', '%22', '%27'],
            trim($url)
        );
    }

    /**
     * جایگزینی نهایی لینک‌ها در متن یک بخش:
     * 1) [anchor](PRODUCT_12) → [anchor](url امن)
     * 2) هر لینکی که مجاز نیست (URL ساختگی مدل) یا تکراری است → فقط متن anchor
     * 3) placeholder لخت یا ناشناخته (بدون ساختار لینک) → حذف
     *
     * @param  array<string,string>  $links  placeholder => url
     */
    protected function applyLinks(string $content, array $links): string
    {
        $map = array_map(fn ($u) => $this->safeUrl((string) $u), $links);

        // 1) placeholder داخل لینک → URL امن
        $content = (string) preg_replace_callback(
            '/\]\(\s*((?:PRODUCT|CATEGORY)_\d+)\s*\)/u',
            fn ($m) => '](' . ($map[$m[1]] ?? $m[1]) . ')',
            $content
        );

        // 2) فقط لینک‌های مجاز و فقط اولین تکرار هر URL
        $allowed = array_flip($map);
        $used = [];

        $content = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/u',
            function ($m) use ($allowed, &$used) {
                if (! isset($allowed[$m[2]]) || isset($used[$m[2]])) {
                    return $m[1];
                }

                $used[$m[2]] = true;

                return $m[0];
            },
            $content
        );

        // 3) placeholder لخت یا ناشناخته
        $content = (string) preg_replace('/\(?\b(?:PRODUCT|CATEGORY)_\d+\b\)?/u', '', $content);

        return trim((string) preg_replace('/[ \t]{2,}/u', ' ', $content));
    }

    /** متن امن برای سلول جدول: حذف تگ، decode موجودیت‌ها، حذف | و خط جدید. */
    protected function tableText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['|', "\r", "\n"], ['/', ' ', ' '], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** helper ساخت JSON Schema شیء (strict: همه‌ی کلیدها required). */
    protected function obj(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    // ===============================================================
    //  فراخوانی AI (callGapgptChat + ثبت مصرف توکن)
    // ===============================================================

    protected function chat(
        string $model,
        string $system,
        string $user,
        string $schemaName,
        array $schema,
        float $temperature = 0.5,
        int $timeout = 120,
        int $maxTokens = 2000,
        bool $localize = true,
        ?string $reasoningEffort = null,
    ): array {
        $startedAt = microtime(true);

        if ($localize) {
            $system = ContentLanguage::localize($system, $this->language);
        }

        $payload = [
            'model' => $model,
            // توکن‌های reasoning هم از همین سقف کم می‌شوند؛ بدون این حاشیه خروجی‌های کوتاه روی مدل reasoning ناقص می‌مانند.
            'max_completion_tokens' => preg_match('/^(gpt-5|o\d)/i', $model) ? $maxTokens + 2000 : $maxTokens,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
            ],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (preg_match('/^(gpt-5|o\d)/i', $model)) {
            if (! self::$reasoningEffortUnsupported) {
                $payload['reasoning_effort'] = $reasoningEffort ?? self::REASONING_EFFORT;
            }
        } else {
            $payload['temperature'] = $temperature;
        }

        $response = $this->callGapgptChat($payload, $timeout);

        // مقدار غیرپیش‌فرض (مثل 'minimal') رد شد: اول با پیش‌فرض، تا reasoning_effort سراسری خاموش نشود
        if (($payload['reasoning_effort'] ?? self::REASONING_EFFORT) !== self::REASONING_EFFORT && $response && ! $response->successful() && $this->isUnsupportedParamError($response)) {
            $payload['reasoning_effort'] = self::REASONING_EFFORT;
            $response = $this->callGapgptChat($payload, $timeout);
        }

        // اگر پروکسی reasoning_effort را نپذیرفت، یک بار بدون آن تکرار می‌شود
        if (isset($payload['reasoning_effort']) && $response && ! $response->successful() && $this->isUnsupportedParamError($response)) {
            self::$reasoningEffortUnsupported = true;
            unset($payload['reasoning_effort']);
            $response = $this->callGapgptChat($payload, $timeout);
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if (! $response || ! $response->successful()) {
            Log::error('ai.product_article_writer.chat.request_failed', [
                'model' => $model,
                'schema' => $schemaName,
                'status' => $response?->status(),
                'duration_ms' => $durationMs,
                'body' => Str::limit((string) $response?->body(), 2000),
            ]);

            throw new RuntimeException('AI request failed: '.($response?->body() ?? 'No active API key responded.'));
        }

        $usage = $response->json('usage');

        // مصرف توکن همین‌جا ثبت می‌شود، حتی اگر parse بعداً fail شود (توکن مصرف شده است)
        if ($usage && app()->bound('current_ai_user')) {
            $this->recordAiTokenUsage(user: app('current_ai_user'), serviceName: "ai.{$schemaName}", usage: $usage, model: $model);
        }

        $message = $response->json('choices.0.message.content');
        $finishReason = $response->json('choices.0.finish_reason');
        $parsed = is_string($message) ? json_decode($message, true) : null;

        if (! is_array($parsed)) {
            Log::error('ai.product_article_writer.chat.unparsable_response', [
                'model' => $model,
                'schema' => $schemaName,
                'finish_reason' => $finishReason,
                'raw' => Str::limit((string) $message, 1500),
            ]);

            throw new RuntimeException($finishReason === 'length'
                ? 'پاسخ هوش مصنوعی به‌دلیل رسیدن به سقف توکن ناقص ماند.'
                : 'پاسخ هوش مصنوعی قابل پردازش نیست.');
        }

        Log::info('ai.product_article_writer.chat.response', [
            'model' => $model,
            'schema' => $schemaName,
            'duration_ms' => $durationMs,
            'usage' => $usage,
            'finish_reason' => $finishReason,
        ]);

        return $parsed;
    }
}
