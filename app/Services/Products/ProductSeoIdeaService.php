<?php

namespace App\Services\Products;

use App\Services\TopicHistory;
use App\Models\Site;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use App\Services\AI\RecordsTokenUsage;
use App\Services\SearchConsoleInsightsService;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ProductSeoIdeaService
{
    use RecordsTokenUsage;
    use UsesGapgptApiKey;

    protected const CHEAP_MODEL = 'gpt-4o-mini';
    protected const IDEA_MODEL = 'gpt-5.4-mini';
    protected const STYLES = ['price', 'comparison', 'buying_guide'];
    protected const MAX_CATEGORIES_IN_PROMPT = 60;
    protected const MAX_CATEGORIES_SELECTED = 3;
    protected const MAX_MANUAL_IDEA_PRODUCTS = 10; // باید با MAX_IDEA_PRODUCTS در کنترلر یکی باشد
    protected const POOL_SIZE = 300;    // تعداد محصولی که از دیتابیس خوانده می‌شود
    protected const SAMPLE_SIZE = 70;   // تعداد محصولی که به AI نشان داده می‌شود (فقط برای شناخت برندها/نوع‌ها)
    protected const PRODUCTS_PER_IDEA = 5;
    protected const MIN_PRODUCTS_PER_IDEA = 2;  // کمتر از این، ایده حذف می‌شود
    protected const PER_IDEA_CANDIDATES = 40;   // سقف کاندیدای هر ایده از دیتابیس
    protected const PROXIMITY_WINDOW = 3;       // حداکثر فاصله‌ی مجاز (به کلمه) بین کلمات یک کلیدواژه
    protected const MIN_KEYWORD_LENGTH = 3;     // کلیدواژه‌ی تک‌کلمه‌ای کوتاه‌تر از این رد می‌شود

    /**
     * @param  int[]  $productIds  اگر خالی نباشد، کاربر خودش محصولات را انتخاب کرده و ایده‌ها فقط درباره‌ی همین‌هاست.
     */
    public function generate(Site $site, string $request = '', array $productIds = []): array
    {
        $request = trim($request);

        if ($productIds !== []) {
            return $this->generateForProducts($site, $request, $productIds);
        }

        // مرحله ۱: انتخاب دسته‌بندی
        $categories = $this->loadCategories($site);

        if ($categories->isEmpty()) {
            throw new RuntimeException('No products or categories have been synced for this site yet.');
        }

        $categoryIds = $this->selectCategories($site, $categories, $request);
        $selected = $categories->whereIn('id', $categoryIds)->values();

        // مرحله ۲: خواندن محصولات واقعی و نمونه‌گیری متنوع (پخش روی بازه‌ی قیمت)
        $map = $this->descendantMap($selected->pluck('id')->all(), $site->id);
        $pool = $this->loadPool($site, $map);

        if ($pool->isEmpty()) {
            throw new RuntimeException('No in-stock, priced products were found in the selected categories.');
        }

        $sample = $this->samplePool($pool);

        // مرحله ۳: ساخت ۳ ایده با focus_keyword (AI محصولات واقعی را برای شناخت برندها می‌بیند)
        $ideas = $this->generateIdeas($site, $selected, $sample, $request);

        // مرحله ۴: انتخاب دقیق محصولات بر اساس کلیدواژه (بدون AI) + حذف ایده‌های بی‌محصول
        return $this->finalize($site, $ideas, $selected, $pool, $map);
    }

    // =====================================================================
    //  حالت دستی: کاربر خودش محصولات را انتخاب کرده است
    // =====================================================================

protected function generateForProducts(Site $site, string $request, array $productIds): array
{
    // ترتیب انتخاب کاربر حفظ می‌شود (بدون تکراری)
    $orderedIds = array_values(array_unique(array_map('intval', $productIds)));

    $products = SiteProduct::where('site_id', $site->id)
        ->whereIn('id', $orderedIds)
        ->get(['id', 'name', 'price', 'category_id'])
        ->map(fn ($p) => [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'price' => (int) $p->price,
            'category_id' => (int) ($p->category_id ?? 0),
        ])
        ->sortBy(fn ($p) => array_search($p['id'], $orderedIds, true))
        ->values();

    if ($products->isEmpty()) {
        throw new RuntimeException('Selected products not found.');
    }

    $categoryIds = $products->pluck('category_id')->filter()->unique()->values()->all();
    $categoryNames = SiteProductCategory::where('site_id', $site->id)
        ->whereIn('id', $categoryIds)
        ->pluck('name', 'id');

    $ideas = $this->generateIdeasForProducts($site, $products, $categoryNames, $request);

    return $this->finalizeManual($site, $ideas, $products, $categoryNames);
}
protected function generateIdeasForProducts(Site $site, $products, $categoryNames, string $request): array
{
    $multi = $products->count() >= 2;

    // مقایسه حداقل دو محصول لازم دارد
    $styles = $multi ? self::STYLES : ['price', 'buying_guide'];
    $count = count($styles);

    $productLines = $products
        ->map(fn ($p) => "{$p['id']}|".Str::limit($p['name'], 80, '')."|{$p['price']}|".$categoryNames->get($p['category_id'], ''))
        ->implode("\n");

    $user = "Store: {$site->domain}\nNiche: {$site->niche}\n\n"
        ."Products chosen by the user (id|name|price|category), prices in the store's own currency:\n{$productLines}\n\n"
        .'User request: '.($request !== '' ? $request : '(none)');

    // موضوع‌هایی که سایت دارد: مقاله‌های آخر خود سایت + ایده‌های محصول قبلی
    $user .= $this->historyBlock($site);

    $searchConsole = app(SearchConsoleInsightsService::class)->productIdeaEvidenceBlock(
        $site,
        [...$products->pluck('name')->all(), ...$categoryNames->values()->all()],
    );

    if ($searchConsole !== '') {
        $user .= "\n\n".$searchConsole;
    }

    $parsed = $this->ask(
        $site, self::IDEA_MODEL, 'product_seo_manual_ideas', 'ai.product_seo_ideas', 0.4,
        [
            'type' => 'object',
            'properties' => [
                'ideas' => [
                    'type' => 'array',
                    'minItems' => $count,
                    'maxItems' => $count,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            // عبارتی که کاربر واقعاً در گوگل تایپ می‌کند (ذخیره نمی‌شود، فقط مدل را منضبط می‌کند)
                            'focus_keyword' => ['type' => 'string', 'maxLength' => 45],
                            'title' => ['type' => 'string', 'maxLength' => 60],
                            'content_style' => ['type' => 'string', 'enum' => $styles],
                            'angle' => ['type' => 'string', 'maxLength' => 160],
                        ],
                        'required' => ['focus_keyword', 'title', 'content_style', 'angle'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['ideas'],
            'additionalProperties' => false,
        ],
        <<<'PROMPT'
شما متخصص ارشد سئوی فروشگاه‌های اینترنتی ایرانی و تحلیل‌گر کلیدواژه در گوگل فارسی هستید. کاربر چند محصول مشخص را انتخاب کرده و باید برای آن‌ها عنوان مقاله‌ی سئو بسازید. عنوان‌ها باید دقیقاً شبیه عبارت‌هایی باشند که خریداران ایرانی در گوگل جستجو می‌کنند.

== قاعده‌ی محصولات ==
- هر ایده درباره‌ی «همه‌ی» محصولات انتخاب‌شده با هم است. محصولات را بین ایده‌ها تقسیم نکن.
- نقطه‌ی مشترک محصولات (برند، نوع محصول، مدل‌ها، کاربرد) را پیدا کن و عنوان را روی آن بساز.
- اگر یک محصول انتخاب شده، همه‌ی ایده‌ها درباره‌ی همان محصول‌اند با نیت جستجوی متفاوت.
- اگر محصولات از انواع متفاوت‌اند (مثلاً میکروفون، اسپیکر و هدست)، از برند مشترک و دسته‌ی کلی‌ترِ پرجستجو استفاده کن (مثلاً «لوازم صوتی JBL»)، نه جمله‌ی انشایی.

== focus_keyword ==
- عبارت اصلی جستجو، ۲ تا ۴ کلمه: «نوع محصول + برند + مدل/سری»، دقیقاً با همان نام‌هایی که در فهرست محصولات هست. مثال: «هدست گیمینگ JBL Quantum».
- عنوان باید با focus_keyword یا با کلمه‌ی سبک (قیمت، مقایسه، راهنمای خرید) + focus_keyword شروع شود.
- focus_keyword سه ایده می‌تواند یکی باشد؛ تفاوت ایده‌ها در نیت جستجو است، نه در محصول.

== فرمول عنوان هر سبک (نیت جستجوی خرید) ==
- price: «قیمت {focus_keyword}» + در صورت جا داشتن «+ خرید» یا لیست مدل‌ها.
  نمونه: «قیمت هدست گیمینگ JBL Quantum + خرید اصل»، «قیمت میکروفون، اسپیکر و هدست JBL».
- comparison: «مقایسه {محصول/مدل A} با {B} (و C)» + عبارت تصمیم‌ساز مثل «کدام بهتر است؟» یا «تفاوت‌ها».
  نمونه: «مقایسه هدست JBL Quantum 100، 400 و 810؛ کدام بهتر است؟».
- buying_guide: «راهنمای خرید {focus_keyword}» + «کدام مدل بخریم؟» یا «چطور انتخاب کنیم؟».
  نمونه: «راهنمای خرید هدست گیمینگ JBL Quantum؛ کدام مدل؟».
این‌ها فقط الگو هستند؛ نام‌ها و مدل‌ها را از محصولات واقعی بگیر و نمونه‌ها را کپی نکن.

== قواعد کیفیت عنوان ==
- طول عنوان بین ۳۵ تا ۵۵ کاراکتر؛ هرگز بیشتر از ۶۰. بلندتر در نتایج گوگل بریده می‌شود.
- کلمه‌ی کلیدی اصلی در ابتدای عنوان.
- فقط از کلماتی استفاده کن که مردم واقعاً جستجو می‌کنند: قیمت، خرید، مقایسه، تفاوت، راهنمای خرید، کدام بهتر است، اصل، مدل‌ها.
- عنوان باید یک نیت جستجوی روشن داشته باشد (می‌خواهم قیمت بدانم / دو چیز را مقایسه کنم / انتخاب کنم). عنوان مبهم یا انشایی ممنوع.
- ممنوع: جمله‌های توصیفی و غیرقابل‌جستجو مثل «برای انتخاب بین بودجه و امکانات»، «برای چه کسانی مناسب است»، «شیک و باکیفیت»، «بهترین‌ها در بازار»، «همه‌چیز درباره‌ی...»، «معرفی محصولات».
- نام فروشگاه یا دامنه در عنوان نیاید.
- قیمت مشخص (مبلغ) در عنوان نیاید؛ شماره‌ی مدل محصول مجاز است.
- از علامت‌های اضافی (!، ؟؟، چند «:» و «|») پرهیز کن؛ حداکثر یک «؛» یا «+» یا «؟».
- نام برند و مدل را همان‌طور که در محصولات آمده بنویس.
- سه عنوان باید با هم تکراری نباشند، چون هر کدام نیت جستجوی متفاوتی دارد.
- به‌جز نام برند و مدل، فقط کلمه‌های ساده‌ای بیاور که خریدار معمولی در گوگل تایپ می‌کند؛ اصطلاح تخصصی، کلمه‌ی انگلیسیِ فارسی‌نویسی‌شده و گیومه ممنوع.
- عنوان عبارت جستجو است، نه جمله‌ی کامل یا سؤال گفت‌وگویی بلند؛ فقط یک پسوند کوتاه مثل «کدام بهتر است؟» مجاز است.
- عنوان باید از نظر دستور زبان فارسی درست و روان باشد و انسانی خوانده شود، مثل عنوانی که ویراستار یک فروشگاه واقعی می‌نویسد؛ ردیف کلمه‌ی کلیدی پشت سر هم ممنوع.
- اگر بخش «Product article ideas already suggested» یا «The site owner's own most recent articles» آمده، عنوانی نساز که همان را با کلمه‌های دیگر تکرار کند.

== سایر ==
- تعداد ایده‌ها و سبک‌های مجاز را از schema بگیر؛ هر سبک دقیقاً یک‌بار.
- اگر کاربر درخواست مشخصی نوشته (مثلاً «برای گیمرها»)، آن را در عنوان‌ها لحاظ کن، به شرط اینکه جستجوپذیر بماند.
- در angle در یک جمله بنویس چه کسی با چه نیتی این عبارت را جستجو می‌کند.
فقط JSON.
PROMPT,
        $user
    );

    // محصولات را AI انتخاب نمی‌کند؛ همه‌ی ایده‌ها همه‌ی محصولات کاربر را می‌گیرند
    $allIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
    $ideas = [];

    foreach ($parsed['ideas'] ?? [] as $idea) {
        $title = $this->trimTitle((string) ($idea['title'] ?? ''));

        if ($title === '') {
            continue;
        }

        $ideas[] = [
            'title' => $title,
            'content_style' => in_array($idea['content_style'] ?? '', $styles, true)
                ? $idea['content_style']
                : 'buying_guide',
            'product_ids' => $allIds,
        ];
    }

    if ($ideas === []) {
        throw new RuntimeException('The AI returned no ideas.');
    }

    $ideas = $this->dropDuplicateIdeas($site, $ideas);

    return $ideas;
}

/** چند مقاله‌ی آخر خود سایت و آخرین ایده‌های محصول همین سایت، تا AI همان‌ها را دوباره نسازد. */
protected function historyBlock(Site $site): string
{
    $block = app(TopicHistory::class)->recentPostsBlock($site);
    $previous = \App\Models\ProductSeoIdea::where('site_id', $site->id)->latest('id')->limit(15)->pluck('title');

    if ($previous->isNotEmpty()) {
        $block .= ($block !== '' ? "\n\n" : '')
            ."Product article ideas already suggested for this store (do NOT repeat the same title or the same search intent for the same products; pick a different, still searchable angle):\n"
            .$previous->map(fn ($t) => "- {$t}")->implode("\n");
    }

    return $block !== '' ? "\n\n".$block : '';
}

/**
 * ایده‌ای که عملاً همان یک پست سایت یا ایده‌ی محصول قبلی (یا ایده‌ی دیگری از همین دسته) است حذف می‌شود.
 *
 * @param  array<int, array{title:string}>  $ideas
 */
protected function dropDuplicateIdeas(Site $site, array $ideas): array
{
    $history = app(TopicHistory::class);
    $seen = $history->existingTitleTokens($site, includeArticleIdeas: false);
    $kept = [];

    foreach ($ideas as $idea) {
        if ($history->isDuplicate($idea['title'], $seen)) {
            Log::info('ProductSeoIdeaService: duplicate idea dropped', ['site_id' => $site->id, 'title' => $idea['title']]);

            continue;
        }

        $seen[] = $history->tokens($idea['title']);
        $kept[] = $idea;
    }

    if ($kept === []) {
        throw new RuntimeException('All suggested topics were duplicates (already on the site or in earlier ideas). Try again or pick other products.');
    }

    return $kept;
}

/** عنوان بیش از ۶۰ کاراکتر را روی مرز کلمه کوتاه می‌کند (schema سخت‌گیرانه maxLength را تضمین نمی‌کند). */
protected function trimTitle(string $title): string
{
    $title = trim(preg_replace('/\s+/u', ' ', str_replace(['«', '»', '"'], '', $title)));

    if (mb_strlen($title) <= 60) {
        return $title;
    }

    $cut = mb_substr($title, 0, 60);
    $space = mb_strrpos($cut, ' ');

    return trim($space !== false && $space > 35 ? mb_substr($cut, 0, $space) : $cut, " ؛:+-،");
}
protected function finalizeManual(Site $site, array $ideas, $products, $categoryNames): array
{
    // همه‌ی محصولات انتخابی (به ترتیب انتخاب کاربر) در هر ایده می‌آیند
    $products = $products->take(self::MAX_MANUAL_IDEA_PRODUCTS)->values();
    $ids = $products->pluck('id')->map(fn ($id) => (int) $id)->all();

    $catIds = $products->pluck('category_id')->map(fn ($id) => (int) $id)->filter();

    $categories = $catIds->unique()->values()
        ->map(fn ($cid) => ['id' => (int) $cid, 'name' => (string) $categoryNames->get($cid, '')])
        ->all();

// دسته‌ی هدف: دسته‌ی «کارکرده» نباشد، بعد بیشترین محصول، بعد دسته‌ی کلی‌تر (کم‌عمق‌تر)
$primary = $this->pickPrimaryCategory($site, $catIds);    $targets = $this->loadTargets($site, $catIds->unique()->values()->all());
    $target = $targets[$primary] ?? [
        'id' => null,
        'url' => $this->siteBaseUrl($site),
        'title' => (string) $site->domain,
    ];

    $result = [];

    foreach ($ideas as $idea) {
        // مقایسه حداقل دو محصول لازم دارد
        if ($idea['content_style'] === 'comparison' && count($ids) < 2) {
            continue;
        }

        $result[] = [
            'title' => $idea['title'],
            'content_style' => $idea['content_style'],
            'categories' => $categories,
            'product_ids' => $ids,
            'target_category_id' => $target['id'],
            'target_url' => $target['url'],
            'target_url_title' => $target['title'],
        ];
    }

    if ($result === []) {
        throw new RuntimeException('No ideas were created for the selected products. Please try again.');
    }

    return $result;
}
protected function pickPrimaryCategory(Site $site, $catIds): int
{
    $counts = $catIds->countBy();

    if ($counts->isEmpty()) {
        return 0;
    }

    $cats = SiteProductCategory::where('site_id', $site->id)->get(['id', 'name', 'parent_id'])->keyBy('id');

    $depth = function ($id) use ($cats): int {
        $d = 0;
        $guard = 0;

        while (isset($cats[$id]) && $cats[$id]->parent_id && $guard++ < 10) {
            $d++;
            $id = $cats[$id]->parent_id;
        }

        return $d;
    };

    $isUsed = fn ($id): int => preg_match('/کارکرده|دست\s?دوم|استوک/u', (string) ($cats[$id]->name ?? '')) ? 1 : 0;

    return (int) $counts->keys()
        ->sortBy(fn ($id) => [$isUsed($id), -$counts[$id], $depth($id)])
        ->first();
}
    // =====================================================================

    protected function loadCategories(Site $site)
    {
        $counts = SiteProduct::where('site_id', $site->id)
            ->where('stock_status', 'instock')
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id');

        return SiteProductCategory::where('site_id', $site->id)
            ->whereIn('id', $counts->keys())
            ->get(['id', 'name', 'parent_id'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'parent_id' => (int) ($c->parent_id ?? 0),
                'count' => (int) $counts[$c->id],
            ])
            ->sortByDesc('count')
            ->take(self::MAX_CATEGORIES_IN_PROMPT)
            ->values();
    }

    protected function selectCategories(Site $site, $categories, string $request): array
    {
        $ids = $categories->pluck('id')->all();

        $lines = $categories->map(fn ($c) => "{$c['id']}|{$c['name']}|{$c['parent_id']}|{$c['count']}")->implode("\n");
        $user = "Store: {$site->domain}\nNiche: {$site->niche}\nCategories (id|name|parent_id|products):\n{$lines}\n\nUser request: "
            .($request !== '' ? $request : '(none)');

        $parsed = $this->ask(
            $site, self::CHEAP_MODEL, 'product_seo_categories', 'ai.product_seo_categories', 0,
            [
                'type' => 'object',
                'properties' => [
                    'category_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'enum' => $ids],
                        'maxItems' => self::MAX_CATEGORIES_SELECTED,
                    ],
                ],
                'required' => ['category_ids'],
                'additionalProperties' => false,
            ],
            'شما دستیار سئوی فروشگاه اینترنتی هستید. از بین دسته‌بندی‌های داده‌شده، حداکثر ۳ دسته انتخاب کن. '
            .'اگر کاربر موضوع مشخصی نوشته، دسته‌هایی را بردار که بیشترین ارتباط را با آن دارند. '
            .'اگر درخواست خالی یا عمومی بود (مثل «بهترین محصولات فروشگاه»)، دسته‌هایی را بردار که بهترین فرصت تولید محتوای سئو هستند: پرمحصول، متنوع و پرجستجو. '
            .'یک دسته و زیردسته‌ی مستقیم‌اش (parent_id) را هم‌زمان انتخاب نکن. فقط JSON.',
            $user
        );

        $picked = array_values(array_intersect($ids, array_map('intval', $parsed['category_ids'] ?? [])));

        return $picked ?: array_slice($ids, 0, self::MAX_CATEGORIES_SELECTED);
    }

    /**
     * نگاشت [شناسه‌ی هر دسته و زیردسته‌هایش => دسته‌ی انتخاب‌شده‌ای که به آن تعلق دارد].
     * دسته‌ی انتخاب‌شده همیشه به خودش نگاشت می‌شود (دسته‌ی تخصصی‌تر برنده است).
     */
    protected function descendantMap(array $rootIds, int $siteId): array
    {
        $map = [];

        foreach ($rootIds as $root) {
            $map[(int) $root] = (int) $root;
        }

        foreach ($rootIds as $root) {
            $current = [(int) $root];

            while ($current !== []) {
                $children = SiteProductCategory::where('site_id', $siteId)
                    ->whereIn('parent_id', $current)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $children = array_values(array_filter($children, fn ($id) => ! isset($map[$id])));

                foreach ($children as $id) {
                    $map[$id] = (int) $root;
                }

                $current = $children;
            }
        }

        return $map;
    }

    protected function loadPool(Site $site, array $map)
    {
        $byRoot = [];

        foreach ($map as $catId => $rootId) {
            $byRoot[$rootId][] = $catId;
        }

        $perRoot = (int) ceil(self::POOL_SIZE / max(1, count($byRoot)));
        $pool = collect();

        foreach ($byRoot as $rootId => $catIds) {
            $rows = SiteProduct::where('site_id', $site->id)
                ->where('stock_status', 'instock')
                ->where('price', '>', 0)
                ->whereIn('category_id', $catIds)
                ->latest('id')
                ->limit($perRoot)
                ->get(['id', 'name', 'price']);

            foreach ($rows as $r) {
                $pool->push([
                    'id' => (int) $r->id,
                    'name' => (string) $r->name,
                    'price' => (int) $r->price,
                    'root' => (int) $rootId,
                ]);
            }
        }

        return $pool;
    }

    /** نمونه‌ی متنوع: از هر دسته محصولاتی که روی کل بازه‌ی قیمت پخش شده‌اند. */
    protected function samplePool($pool): array
    {
        $groups = $pool->groupBy('root');
        $quota = (int) ceil(self::SAMPLE_SIZE / max(1, $groups->count()));
        $sample = [];

        foreach ($groups as $items) {
            $sorted = $items->sortBy('price')->values();
            $n = $sorted->count();

            if ($n <= $quota) {
                foreach ($sorted as $p) {
                    $sample[] = $p;
                }
                continue;
            }

            for ($k = 0; $k < $quota; $k++) {
                $sample[] = $sorted[(int) floor($k * ($n - 1) / max(1, $quota - 1))];
            }
        }

        return collect($sample)->unique('id')->values()->all();
    }

    protected function generateIdeas(Site $site, $selected, array $sample, string $request): array
    {
        $names = $selected->pluck('name', 'id');

        $catLines = $selected->map(fn ($c) => "{$c['id']}|{$c['name']}")->implode("\n");
        $productLines = collect($sample)
            ->map(fn ($p) => "{$p['id']}|".Str::limit($p['name'], 80, '')."|{$p['price']}|".$names->get($p['root'], ''))
            ->implode("\n");

        $user = "Store: {$site->domain}\nNiche: {$site->niche}\n\n"
            ."Selected categories (id|name):\n{$catLines}\n\n"
            ."Store products in stock (id|name|price|category), prices in the store's own currency:\n{$productLines}\n\n"
            .'User request: '.($request !== '' ? $request : '(none)');

        // موضوع‌هایی که سایت دارد: مقاله‌های آخر خود سایت + ایده‌های محصول قبلی
        $user .= $this->historyBlock($site);

        $searchConsole = app(SearchConsoleInsightsService::class)->productIdeaEvidenceBlock(
            $site,
            [...$selected->pluck('name')->all(), ...collect($sample)->pluck('name')->all()],
        );

        if ($searchConsole !== '') {
            $user .= "\n\n".$searchConsole;
        }

        $parsed = $this->ask(
            $site, self::IDEA_MODEL, 'product_seo_ideas', 'ai.product_seo_ideas', 0.6,
            [
                'type' => 'object',
                'properties' => [
                    'ideas' => [
                        'type' => 'array',
                        'minItems' => 3,
                        'maxItems' => 3,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'maxLength' => 60],
                                'content_style' => ['type' => 'string', 'enum' => self::STYLES],
                                // فقط برای منسجم‌شدن خروجی مدل؛ ذخیره نمی‌شود
                                'angle' => ['type' => 'string', 'maxLength' => 160],
                                // برند/نوع مشخصی که دقیقاً در نام محصولات هست (مثلاً «رازی»)
                                'focus_keyword' => ['type' => 'string', 'maxLength' => 40],
                                // فقط برای مقایسه: کلیدواژه‌ی طرف دوم (در غیر این صورت رشته‌ی خالی)
                                'compare_keyword' => ['type' => 'string', 'maxLength' => 40],
                            ],
                            'required' => ['title', 'content_style', 'angle', 'focus_keyword', 'compare_keyword'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['ideas'],
                'additionalProperties' => false,
            ],
            <<<'PROMPT'
شما سردبیر ارشد سئو و متخصص محتوای فروشگاه اینترنتی هستید. با توجه به فهرست «واقعی» محصولات فروشگاه، دقیقاً ۳ ایده مقاله بساز؛ ایده‌هایی که کاربر واقعاً در گوگل جستجو می‌کند و به فروش همین محصولات کمک می‌کند.

قواعد عنوان:
- فارسی روان و طبیعی، حداکثر ۶۰ کاراکتر، با کلمه‌ی کلیدی اصلی در ابتدای عنوان.
- کلمه‌ی کلیدی باید نوع، برند یا مدل مشخصی باشد که در محصولات داده‌شده هست (مثلاً «فندک زیپو»، نه فقط «فندک»).
- عبارت‌های کلی و پرکننده ممنوع: «شیک و باکیفیت»، «بهترین‌ها در بازار»، «همه‌چیز درباره‌ی...»، «معرفی محصولات».
- نام فروشگاه یا دامنه را در عنوان نیاور.
- عدد و قیمت مشخص در عنوان نیاور، مگر اینکه کاربر صریحاً گفته باشد (با همان واحد پولی خودش).
- عنوان شبیه عبارتی باشد که خریدار در گوگل جستجو می‌کند، نه جمله‌ی کامل یا سؤال گفت‌وگویی بلند.
- به‌جز نام برند و مدل، کلمه‌های ساده و روزمره بیاور؛ اصطلاح تخصصی، کلمه‌ی انگلیسیِ فارسی‌نویسی‌شده و گیومه ممنوع.
- عنوان انسانی خوانده شود، مثل عنوانی که ویراستار یک فروشگاه واقعی می‌نویسد، با حرف‌های ربط درست؛ ردیف کلمه‌ی کلیدی پشت سر هم ممنوع.
- اگر بخش «Product article ideas already suggested» یا «The site owner's own most recent articles» آمده، عنوانی نساز که همان را با کلمه‌های دیگر تکرار کند؛ گروه محصول یا زاویه‌ی دیگری انتخاب کن.

سه ایده باید سه سبک متفاوت باشند و هر سبک دقیقاً یک‌بار بیاید:
1) price (قیمت و اقتصادی): کاربر قیمت روز یا گزینه‌ی ارزان یک نوع مشخص محصول را می‌خواهد. نمونه‌ی عنوان: «قیمت فندک زیپو اصل + لیست مدل‌های موجود».
2) comparison (مقایسه): دو گزینه‌ی واقعی و قابل‌مقایسه را که هر دو در فهرست هستند رو در رو بگذار. نمونه: «فندک زیپو یا فندک بنزینی معمولی؟ تفاوت‌ها».
3) buying_guide (راهنمای خرید): راهنمای انتخاب یک نوع مشخص بر اساس کاربرد، سلیقه یا بودجه. نمونه: «راهنمای خرید فندک زیپو برای هدیه».

قواعد focus_keyword و compare_keyword (بسیار مهم):
- focus_keyword: برند یا نوع اصلی مقاله، دقیقاً همان‌طور که در «نام محصولات» فهرست آمده (مثلاً «رازی»، «زیپو»). اگر عنوان درباره‌ی یک برند مشخص است، همان برند باید focus_keyword باشد. کوتاه و دقیق بنویس (یک یا دو کلمه).
- focus_keyword باید حتماً در نام حداقل ۲ محصول فهرست وجود داشته باشد.
- compare_keyword: فقط برای سبک comparison، کلیدواژه‌ی طرف دوم مقایسه که آن هم در نام محصولات فهرست هست؛ در بقیه‌ی سبک‌ها رشته‌ی خالی.
- انتخاب محصول را خودت انجام نده؛ سیستم محصولات را فقط بر اساس focus_keyword (و compare_keyword) انتخاب می‌کند، پس این کلیدواژه‌ها باید دقیقاً با موضوع عنوان بخوانند.

قواعد تنوع:
- هر ایده باید موضوع یا گروه محصولی متفاوتی را هدف بگیرد. اگر چند دسته وجود دارد، از دسته‌های مختلف استفاده کن؛ اگر یک دسته است، ایده‌ها را با برند، نوع یا کاربرد متفاوت از هم جدا کن.
- focus_keyword سه ایده باید با هم متفاوت باشند.
- اگر کاربر موضوع مشخصی نوشته، هر سه ایده باید با آن مرتبط باشند. اگر درخواست خالی یا عمومی بود (مثل «بهترین محصولات فروشگاه» یا «راهنمای خرید پرفروش‌ها»)، آن را نادیده بگیر و از بین فهرست، موضوع‌هایی را انتخاب کن که بیشترین پتانسیل جستجو دارند.
- در فیلد angle در یک جمله بنویس مخاطب و نیت جستجوی این ایده چیست.
فقط JSON.
PROMPT,
            $user
        );

        $ideas = [];

        foreach ($parsed['ideas'] ?? [] as $idea) {
            $title = $this->trimTitle((string) ($idea['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $ideas[] = [
                'title' => $title,
                'content_style' => in_array($idea['content_style'] ?? '', self::STYLES, true)
                    ? $idea['content_style']
                    : 'buying_guide',
                'focus_keyword' => trim((string) ($idea['focus_keyword'] ?? '')),
                'compare_keyword' => trim((string) ($idea['compare_keyword'] ?? '')),
            ];
        }

        if ($ideas === []) {
            throw new RuntimeException('The AI returned no ideas.');
        }

        return $this->dropDuplicateIdeas($site, $ideas);
    }

    protected function finalize(Site $site, array $ideas, $selected, $pool, array $map): array
    {
        $rootNames = $selected->pluck('name', 'id');
        $targets = $this->loadTargets($site, $selected->pluck('id')->all());
        $fallbackRoot = (int) ($selected->first()['id'] ?? 0);
        $used = [];
        $result = [];

        foreach ($ideas as $idea) {
            $picked = $this->pickProductsForIdea($site, $idea, $pool, $used, $map);

            // محصول مرتبط کافی نبود: ایده را نگه نمی‌داریم تا مقاله‌ی نامرتبط ساخته نشود
            if (count($picked) < self::MIN_PRODUCTS_PER_IDEA) {
                Log::warning('ProductSeoIdeaService: not enough matching products, idea skipped', [
                    'site_id' => $site->id,
                    'title' => $idea['title'],
                    'focus_keyword' => $idea['focus_keyword'],
                    'compare_keyword' => $idea['compare_keyword'],
                    'found' => count($picked),
                ]);

                continue;
            }

            $ids = array_map(fn ($p) => (int) $p['id'], $picked);

            foreach ($ids as $id) {
                $used[$id] = true;
            }

            $roots = collect($picked)->map(fn ($p) => (int) ($p['root'] ?: $fallbackRoot));

            $categories = $roots
                ->unique()
                ->values()
                ->map(fn ($rid) => ['id' => (int) $rid, 'name' => (string) $rootNames->get($rid, '')])
                ->all();

            // دسته‌ی هدف = دسته‌ای که بیشترین محصولِ این ایده از آن آمده؛ اگر نبود اولین دسته‌ی انتخاب‌شده
            $primaryRoot = (int) ($roots->countBy()->sortDesc()->keys()->first() ?? $fallbackRoot);
            $target = $targets[$primaryRoot] ?? $targets[$fallbackRoot] ?? [
                'id' => $primaryRoot ?: null,
                'url' => $this->siteBaseUrl($site),
                'title' => (string) $rootNames->get($primaryRoot, $site->domain),
            ];

            $result[] = [
                'title' => $idea['title'],
                'content_style' => $idea['content_style'],
                'categories' => $categories,
                'product_ids' => $ids,
                'target_category_id' => $target['id'],
                'target_url' => $target['url'],
                'target_url_title' => $target['title'],
            ];
        }

        if ($result === []) {
            throw new RuntimeException('Not enough related products were found for the suggested ideas. Please try again.');
        }

        return $result;
    }

    /**
     * محصولات یک ایده را دقیقاً بر اساس focus_keyword (و برای مقایسه compare_keyword) انتخاب می‌کند.
     * هیچ محصول نامرتبطی اضافه نمی‌شود.
     *
     * @return array<int, array{id:int,name:string,price:int,root:int}>
     */
    protected function pickProductsForIdea(Site $site, array $idea, $pool, array $used, array $map): array
    {
        $focus = (string) ($idea['focus_keyword'] ?? '');
        $compare = (string) ($idea['compare_keyword'] ?? '');

        if (trim($focus) === '') {
            return [];
        }

        $available = $pool->filter(fn ($p) => ! isset($used[$p['id']]))->values();

        $fromPool = fn (string $kw) => $available
            ->filter(fn ($p) => $this->nameMatches($p['name'], $kw))
            ->values();

        $main = $fromPool($focus);

        // اگر در pool کم بود، مستقیم از دیتابیس همان سایت (داخل دسته‌های انتخابی) جستجو کن
        if ($main->count() < self::PRODUCTS_PER_IDEA) {
            $main = $this->searchDatabaseByKeyword($site, $focus, $used, $map, $main);
        }

        if (($idea['content_style'] ?? '') === 'comparison' && trim($compare) !== '') {
            $other = $fromPool($compare);

            if ($other->count() < 2) {
                $other = $this->searchDatabaseByKeyword($site, $compare, $used, $map, $other);
            }

            // مقایسه بدون هر دو طرف معنا ندارد
            if ($main->isEmpty() || $other->isEmpty()) {
                return [];
            }

            // هیچ محصولی در هر دو طرف تکرار نشود
            $mainIds = $main->pluck('id')->all();
            $other = $other->reject(fn ($p) => in_array($p['id'], $mainIds, true))->values();

            if ($other->isEmpty()) {
                return [];
            }

            $selected = $this->spreadByPrice($main, 3)->merge($this->spreadByPrice($other, 2));
        } else {
            $selected = $this->spreadByPrice($main, self::PRODUCTS_PER_IDEA);
        }

        return $selected->unique('id')->take(self::PRODUCTS_PER_IDEA)->values()->all();
    }

    /** از لیست، تا $n محصول که روی بازه‌ی قیمت پخش شده‌اند (ارزان تا گران). */
    protected function spreadByPrice($items, int $n)
    {
        $sorted = $items->sortBy('price')->values();
        $count = $sorted->count();

        if ($count <= $n) {
            return $sorted;
        }

        $out = collect();

        for ($k = 0; $k < $n; $k++) {
            $out->push($sorted[(int) floor($k * ($count - 1) / max(1, $n - 1))]);
        }

        return $out->unique('id')->values();
    }

    /**
     * جستجوی محصولات موجود و قیمت‌دار بر اساس کلیدواژه، فقط داخل دسته‌های انتخابی و زیردسته‌هایشان.
     * همه‌ی کلمات کلیدواژه باید در نام محصول باشند (نه لزوماً کنار هم).
     */
    protected function searchDatabaseByKeyword(Site $site, string $keyword, array $used, array $map, $existing)
    {
        $tokens = $this->tokens($keyword);

        if ($tokens === [] || $map === []) {
            return $existing;
        }

        $query = SiteProduct::where('site_id', $site->id)
            ->where('stock_status', 'instock')
            ->where('price', '>', 0)
            ->whereIn('category_id', array_keys($map));

        foreach ($tokens as $token) {
            $query->where('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $token).'%');
        }

        if ($used !== []) {
            $query->whereNotIn('id', array_keys($used));
        }

        $rows = $query->latest('id')
            ->limit(self::PER_IDEA_CANDIDATES)
            ->get(['id', 'name', 'price', 'category_id']);

        $have = $existing->pluck('id')->all();

        foreach ($rows as $r) {
            if (in_array((int) $r->id, $have, true)) {
                continue;
            }

            // دقت نهایی روی نام نرمال‌شده (اختلاف ی/ک عربی و فارسی)
            if (! $this->nameMatches((string) $r->name, $keyword)) {
                continue;
            }

            $existing->push([
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'price' => (int) $r->price,
                'root' => (int) ($map[(int) $r->category_id] ?? 0),
            ]);
        }

        return $existing->values();
    }

    /**
     * تطبیق سخت‌گیرانه‌ی کلیدواژه با نام محصول:
     * - فقط «کلمه‌ی کامل» (نه زیررشته): «رازی» با «رازیانه» یا «موس» با «موسسه» تطبیق نمی‌خورد.
     * - کلیدواژه‌ی چندکلمه‌ای: همه‌ی کلمات باید کامل و «نزدیک هم» باشند (به هر ترتیب)،
     *   پس «چسب رازی» با «چسب همه‌کاره رازی» می‌خواند، ولی با محصولی که این دو کلمه در دو سر نامش پراکنده‌اند نه.
     */
    protected function nameMatches(string $name, string $keyword): bool
    {
        $tokens = $this->tokens($keyword);

        if ($tokens === []) {
            return false;
        }

        $words = $this->words($name);

        if ($words === []) {
            return false;
        }

        if (count($tokens) === 1) {
            return in_array($tokens[0], $words, true);
        }

        $positions = [];

        foreach ($tokens as $token) {
            $found = array_keys($words, $token, true);

            if ($found === []) {
                return false;
            }

            $positions[$token] = $found;
        }

        $span = count($tokens) - 1 + self::PROXIMITY_WINDOW;

        // هر کلمه‌ی کلیدواژه را به‌عنوان لنگر امتحان کن؛ بقیه باید داخل بازه‌ی نزدیک آن باشند
        foreach ($positions as $anchorPositions) {
            foreach ($anchorPositions as $start) {
                $ok = true;

                foreach ($positions as $list) {
                    $near = array_filter($list, fn ($i) => $i >= $start && $i <= $start + $span);

                    if ($near === []) {
                        $ok = false;
                        break;
                    }
                }

                if ($ok) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * کلمات یکتای کلیدواژه. کلیدواژه‌ی خیلی کوتاه/کلی (مثل «جا» یا «ی») رد می‌شود
     * تا به‌جای تطبیق با همه‌چیز، هیچ تطبیقی نداشته باشیم.
     */
    protected function tokens(string $keyword): array
    {
        $tokens = array_values(array_unique($this->words($keyword)));

        if (count($tokens) === 1) {
            return mb_strlen($tokens[0]) >= self::MIN_KEYWORD_LENGTH ? $tokens : [];
        }

        // در کلیدواژه‌ی چندکلمه‌ای، کلمه‌های تک‌حرفی نادیده گرفته می‌شوند
        return array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2));
    }

    /** نام/عبارت را نرمال و به آرایه‌ی کلمات کامل تبدیل می‌کند. */
    protected function words(string $text): array
    {
        $normalized = $this->normalizeFa($text);

        return $normalized === ''
            ? []
            : array_values(array_filter(explode(' ', $normalized), fn ($w) => $w !== ''));
    }

    /**
     * یکسان‌سازی حروف عربی/فارسی، ارقام فارسی/عربی، نیم‌فاصله و علائم (خط تیره، پرانتز، /، ...)
     * به فاصله، تا «ماوس-رازر» و «ماوس رازر» و «(رازر)» یکسان دیده شوند.
     */
    protected function normalizeFa(string $text): string
    {
        $text = strtr($text, [
            'ي' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه',
            'أ' => 'ا', 'إ' => 'ا', 'ؤ' => 'و',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        // هر چیزی که حرف یا عدد نیست (شامل نیم‌فاصله) → فاصله
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /** آدرس و عنوان هر دسته‌ی انتخاب‌شده را یک‌بار می‌خواند (بدون N+1). */
    public function loadTargets(Site $site, array $categoryIds): array
    {
        $targets = [];

        $rows = SiteProductCategory::where('site_id', $site->id)
            ->whereIn('id', $categoryIds)
            ->get(['id', 'name', 'slug', 'url']);

        foreach ($rows as $cat) {
            $url = trim((string) $cat->url);

            if ($url === '') {
                // url خالی است (مثلاً دسته‌ی کراول‌شده)؛ از slug مسیر پیش‌فرض ووکامرس ساخته می‌شود
                $url = $this->siteBaseUrl($site).'/product-category/'.trim((string) $cat->slug, '/').'/';
            } elseif (! preg_match('#^https?://#i', $url)) {
                // اگر url نسبی ذخیره شده باشد (مثل /product-category/x/)
                $url = $this->siteBaseUrl($site).'/'.ltrim($url, '/');
            }

            $targets[(int) $cat->id] = [
                'id' => (int) $cat->id,
                'url' => $url,
                'title' => (string) $cat->name,
            ];
        }

        return $targets;
    }

    public function siteBaseUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);

        if (! preg_match('#^https?://#i', $domain)) {
            $domain = 'https://'.$domain;
        }

        return rtrim($domain, '/');
    }

    protected function ask(Site $site, string $model, string $name, string $serviceName, float $temperature, array $schema, string $system, string $user): array
    {
        $response = $this->callGapgptChat([
            'model' => $model,
            'temperature' => $temperature,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $name, 'strict' => true, 'schema' => $schema],
            ],
            'messages' => [
                ['role' => 'system', 'content' => ContentLanguage::localize($system, ContentLanguage::forSite($site))],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        if (! $response || ! $response->successful()) {
            Log::error('ProductSeoIdeaService: AI request failed', [
                'site_id' => $site->id,
                'step' => $name,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            throw new RuntimeException('AI request failed: '.($response?->body() ?? 'No active API key responded.'));
        }

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);

        if (! is_array($parsed)) {
            throw new RuntimeException('The AI response couldn\'t be processed.');
        }

        $usage = $response->json('usage');
        if ($usage && $site->user) {
            $this->recordAiTokenUsage(user: $site->user, serviceName: $serviceName, usage: $usage, model: $model);
        }

        return $parsed;
    }
}
