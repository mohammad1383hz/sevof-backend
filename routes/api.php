<?php

use App\Http\Controllers\Api\AgentSettingsController;
use App\Http\Controllers\Api\ApiAgentController;
use App\Http\Controllers\Api\ArticleChatController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\ArticleDashboardController;
use App\Http\Controllers\Api\ArticleIdeaController;
use App\Http\Controllers\Api\ArticleWordPressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentChatSessionController;
use App\Http\Controllers\Api\ContentResearchController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\GoogleSearchConsoleController;
use App\Http\Controllers\Api\ImageDownloadController;
use App\Http\Controllers\Api\PageVisitController;
use App\Http\Controllers\Api\PastArticlesController;
use App\Http\Controllers\Api\Product\PageProduct\PageProductSeoController;
use App\Http\Controllers\Api\Product\ProductArticleController;
use App\Http\Controllers\Api\Product\ProductSeoIdeaController;
use App\Http\Controllers\Api\PublicArticleApiController;
use App\Http\Controllers\Api\ReferralRequestController;
use App\Http\Controllers\Api\SearchConsoleInsightsController;
use App\Http\Controllers\Api\SiteConnectionController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteKeywordController;
use App\Http\Controllers\Api\SiteKeywordSuggestionsController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SubscriptionExitSurveyController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\WebsiteEntityController;
use App\Http\Controllers\Wp\WordPressConnectionController;
use App\Http\Middleware\PublicApiConnection;
use Illuminate\Support\Facades\Route;

// routes/api.php


Route::prefix('v1')->group(function () {
    // routes/api.php
        Route::post('/auth/admin/impersonate/exchange', [AuthController::class, 'exchangeImpersonate']);

    // Sign-in: email (+ emailed code) or Google only. Throttled against brute force.
    Route::post('/auth/email/login', [AuthController::class, 'Loginmail'])->middleware('throttle:10,1');
    Route::post('/auth/email/verify', [AuthController::class, 'verifyEmail'])->middleware('throttle:10,1');
    Route::post('/auth/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/admin/impersonate', [AuthController::class, 'impersonate']);
});
    // Google OAuth callback needs no Sanctum session; the encrypted state identifies the site owner.
    Route::get('google-search-console/callback', [GoogleSearchConsoleController::class, 'callback']);
    // Public plan prices for the marketing site; same output as /plans, no auth.
    Route::get('public/plans', [SubscriptionController::class, 'index'])->middleware('throttle:60,1');
    // Stripe webhook: authenticated by the Stripe-Signature header, not Sanctum.
    Route::post('payments/stripe/webhook', [SubscriptionController::class, 'stripeWebhook']);

    Route::prefix('auth')->group(function () {
        Route::post('google', [AuthController::class, 'google'])->middleware('throttle:20,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });
    Route::middleware('auth:sanctum')->group(function () {
    Route::post('/page-visits/start', [PageVisitController::class, 'start']);
    Route::post('/page-visits/{visit}/heartbeat', [PageVisitController::class, 'heartbeat']);
    Route::post('/page-visits/{visit}/end', [PageVisitController::class, 'end']);
});

Route::middleware('auth:sanctum')->prefix('api-keys')->group(function () {
    Route::get('/', [ApiAgentController::class, 'index']);
    Route::post('/', [ApiAgentController::class, 'store']);
    Route::delete('/{apiAgent}', [ApiAgentController::class, 'destroy']);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::get('sites', [SiteController::class, 'index']);
    Route::delete('sites/{site}', [SiteController::class, 'destroy']);
Route::get('/discounts/me', [DiscountController::class, 'me']);
Route::get('announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'index']); // ← جدید
    Route::post('sites', [SiteController::class, 'store']);
    Route::get('sites/{site}', [SiteController::class, 'show']);
    Route::post('sites/{site}/analyze-keywords', [SiteKeywordSuggestionsController::class, 'analyze']);
    Route::get('sites/{site}/keyword-trends', [SiteKeywordSuggestionsController::class, 'trends']);
Route::post('sites/{site}/articles/{article}/banner/regenerate', [ArticleController::class, 'regenerateBanner']);
    // Entity
    Route::get('sites/{site}/entity', [WebsiteEntityController::class, 'show']);
Route::get('tickets', [TicketController::class, 'index']);
    Route::post('tickets', [TicketController::class, 'store']);
    Route::get('tickets/{ticket}', [TicketController::class, 'show']);
    Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply']);
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close']);
    // Subscription
    Route::get('subscription/me', [SubscriptionController::class, 'me']);
    Route::get('subscription/usage', [SubscriptionController::class, 'usageHistory']);
    Route::get('plans', [SubscriptionController::class, 'index']);
    Route::get('plans/{plan}', [SubscriptionController::class, 'show']);
    Route::post('/plans/{plan}/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('payments/stripe/confirm', [SubscriptionController::class, 'confirmStripe'])->middleware('throttle:30,1');







    // Google Search Console: اتصال حساب، انتخاب property و داده واقعی queryهای خود کاربر
    Route::prefix('sites/{site}/search-console')->group(function () {
        Route::post('connect', [GoogleSearchConsoleController::class, 'connect']);
        Route::get('/', [GoogleSearchConsoleController::class, 'status']);
        Route::get('properties', [GoogleSearchConsoleController::class, 'properties']);
        Route::put('property', [GoogleSearchConsoleController::class, 'selectProperty']);
        Route::post('sync', [GoogleSearchConsoleController::class, 'sync']);
        Route::get('queries', [GoogleSearchConsoleController::class, 'queries']);
        Route::delete('/', [GoogleSearchConsoleController::class, 'disconnect']);
        Route::get('insights', [SearchConsoleInsightsController::class, 'show']);
    });

    // کسب درآمد با معرفی: فعلاً فقط ثبت درخواست همکاری (بررسی دستی، بدون کد معرف)
    Route::get('referral-request', [ReferralRequestController::class, 'show']);
    Route::post('referral-request', [ReferralRequestController::class, 'store'])->middleware('throttle:5,1');

    // دانلود تصاویر مقاله با فرمت WebP (دکمه‌ی «تصاویر» کنار کپی Markdown)
    Route::get('images/webp', [ImageDownloadController::class, 'webp'])->middleware('throttle:60,1');

    // آپدیت مقاله‌های گذشته: پست‌های قدیمی خود سایت (نه یوتیکس) از طریق افزونه‌ی وردپرس
    Route::get('sites/{site}/past-articles', [PastArticlesController::class, 'index']);
    Route::post('sites/{site}/past-articles/sync', [PastArticlesController::class, 'sync']);
    Route::get('sites/{site}/past-articles/{wordpressArticle}', [PastArticlesController::class, 'show'])->whereUlid('wordpressArticle');
    Route::post('sites/{site}/past-articles/{wordpressArticle}/refresh', [PastArticlesController::class, 'refresh'])->whereUlid('wordpressArticle');

    // به‌روزرسانی مقاله‌ی منتشرشده با داده‌ی سرچ کنسول: ساخت پیشنهاد → پیش‌نمایش → انتشار/برگرداندن
    Route::post('sites/{site}/articles/{article}/refresh', [SearchConsoleInsightsController::class, 'start'])->whereUlid('article');
    Route::prefix('sites/{site}/article-refreshes/{articleRefresh}')->whereUlid('articleRefresh')->group(function () {
        Route::get('/', [SearchConsoleInsightsController::class, 'showRefresh']);
        Route::post('apply', [SearchConsoleInsightsController::class, 'apply']);
        Route::post('revert', [SearchConsoleInsightsController::class, 'revert']);
        Route::post('discard', [SearchConsoleInsightsController::class, 'discard']);
    });

    // Session-based content conversation history. This layer only persists UI messages.
    Route::prefix('sites/{site}/chat-sessions')->group(function () {
        Route::get('/', [ContentChatSessionController::class, 'index']);
        Route::post('/', [ContentChatSessionController::class, 'store']);
        Route::post('/first-message', [ContentChatSessionController::class, 'storeFirstMessage']);
        Route::get('/{session}', [ContentChatSessionController::class, 'show']);
        Route::post('/{session}/messages', [ContentChatSessionController::class, 'storeMessage']);
        Route::patch('/{session}/messages/{message}', [ContentChatSessionController::class, 'updateMessage']);
    });

    // Content research chat (independent from article ideas)
    Route::prefix('sites/{site}/content-research')->group(function () {
        Route::get('/', [ContentResearchController::class, 'index']);
        Route::post('/', [ContentResearchController::class, 'store']);
        Route::get('/latest/{type}', [ContentResearchController::class, 'latest']);
        Route::get('/{session}', [ContentResearchController::class, 'show']);
    });

    // Article Ideas
    Route::prefix('sites/{site}/article-ideas')->group(function () {
        Route::get('/', [ArticleIdeaController::class, 'index']);
        Route::post('/generate', [ArticleIdeaController::class, 'generate']);
        Route::post('/retry', [ArticleIdeaController::class, 'retry']);
        // Route::post('/score', [ArticleIdeaController::class, 'score']);
        Route::patch('/{idea}/status', [ArticleIdeaController::class, 'updateStatus']);
        Route::patch('/{idea}', [ArticleIdeaController::class, 'update']);
    });

    // Articles
    // ⚠️ route های ثابت (dashboard) باید قبل از route با پارامتر داینامیک ({article}) بیان
    Route::get('sites/{site}/articles/dashboard', [ArticleDashboardController::class, 'index']);
    Route::get('sites/{site}/articles', [ArticleController::class, 'index']);
    Route::post('sites/{site}/ideas/{idea}/articles', [ArticleController::class, 'generate']);
    Route::get('sites/{site}/articles/{article}', [ArticleController::class, 'show']);
    Route::post('sites/{site}/articles/{article}/retry', [ArticleController::class, 'retry']);
    Route::post('sites/{site}/articles/{article}/sections/{section}/regenerate', [ArticleController::class, 'regenerateSection']);
// routes/api.php
Route::post('sites/{site}/articles/{article}/regenerate-image',[ArticleController::class, 'regenerateImage']);
Route::post('sites/{site}/articles/{article}/regenerate-gallery-image', [ArticleController::class, 'regenerateGalleryImage']);
    // Agent Settings
    Route::get('sites/{site}/agent-settings', [AgentSettingsController::class, 'show']);
    Route::patch('sites/{site}/agent-settings', [AgentSettingsController::class, 'update']);
    Route::post('sites/{site}/agent-settings/run-ideas', [AgentSettingsController::class, 'runIdeasNow']);
    Route::post('sites/{site}/agent-settings/run-articles', [AgentSettingsController::class, 'runArticlesNow']);



Route::prefix('sites/{site}/articles/{article}')->group(function () {
    // چت
    Route::get('chat', [ArticleChatController::class, 'index']);
    Route::post('chat', [ArticleChatController::class, 'store']);

    // ویرایش دستی
    Route::patch('/', [ArticleController::class, 'updateField']);
    Route::patch('sections/{section}', [ArticleController::class, 'updateSection']);
    Route::patch('faq/{index}', [ArticleController::class, 'updateFaq']);
});





Route::post('/articles/{article}/wordpress', [ArticleWordPressController::class, 'syncToWordPress']);
Route::delete('/articles/{article}/wordpress', [ArticleWordPressController::class, 'destroyOnWordPress']);
Route::patch('/articles/{article}/wordpress/sync', [ArticleWordPressController::class, 'syncStatus']);


Route::post('/subscription/exit-survey', [SubscriptionExitSurveyController::class, 'store']);










//product seo

Route::get('sites/{site}/product-seo-ideas', [ProductSeoIdeaController::class, 'index']);
Route::post('sites/{site}/product-seo-ideas', [ProductSeoIdeaController::class, 'generate']);
Route::post('/sites/{site}/product-seo-ideas/products/import', [ProductSeoIdeaController::class, 'importProduct']);
Route::get('sites/{site}/product-seo-ideas/products', [ProductSeoIdeaController::class, 'products']);
Route::get('sites/{site}/product-seo-ideas/categories', [ProductSeoIdeaController::class, 'categories']);
Route::patch('sites/{site}/product-seo-ideas/{idea}', [ProductSeoIdeaController::class, 'update']);
Route::prefix('sites/{site}/page-product-seo')->group(function () {
    Route::get('/', [PageProductSeoController::class, 'index']);
  Route::patch('/{optimization}', [PageProductSeoController::class, 'update']);

    Route::post('/', [PageProductSeoController::class, 'store']);
    Route::get('/products', [PageProductSeoController::class, 'products']);
    Route::get('/{optimization}', [PageProductSeoController::class, 'show']);
    Route::post('/{optimization}/approve', [PageProductSeoController::class, 'approve']);
});


Route::prefix('sites/{site}/product-articles')->group(function () {

    Route::post('ideas/{idea}/generate', [ProductArticleController::class, 'generate']);
Route::post('{article}/regenerate-image', [ProductArticleController::class, 'regenerateImage']);
    // مقالات
    Route::get('/', [ProductArticleController::class, 'index']);
    Route::get('{article}', [ProductArticleController::class, 'show']);
    Route::delete('{article}', [ProductArticleController::class, 'destroy']);
    Route::post('{article}/retry', [ProductArticleController::class, 'retry']);

    // بازتولید و ویرایش
    Route::post('{article}/sections/{section}/regenerate', [ProductArticleController::class, 'regenerateSection']);
    Route::patch('{article}/field', [ProductArticleController::class, 'updateField']);
    Route::patch('{article}/sections/{section}', [ProductArticleController::class, 'updateSection']);
    Route::patch('{article}/faq/{index}', [ProductArticleController::class, 'updateFaq'])->whereNumber('index');
});


});





});

Route::middleware(PublicApiConnection::class)->prefix('agent/v1')->group(function (): void {
    Route::get('articles', [PublicArticleApiController::class, 'articles']);
    Route::get('articles/{article}', [PublicArticleApiController::class, 'article']);
    Route::post('article-generation-tasks', [PublicArticleApiController::class, 'createTask']);
    Route::get('article-generation-tasks/{task}', [PublicArticleApiController::class, 'task']);
});

// همان فرایندهای اپ و افزونه، برای سایتِ کلید API (مستندات: صفحه‌ی «مستندات API» در اپ)
// توکن، یا آی‌دی عددی قدیمی برای کلاینت‌هایی که هنوز آن را نگه داشته‌اند
$publicId = '[0-9]+|[0-7][0-9A-Za-z]{25}';
Route::middleware(PublicApiConnection::class.':app')->prefix('agent/v1')->group(function () use ($publicId): void {
    // مقاله‌ی محصول: ایده بساز ← از ایده مقاله بساز ← وضعیت/متن مقاله
    Route::get('product-ideas', [ProductSeoIdeaController::class, 'index']);
    Route::post('product-ideas', [ProductSeoIdeaController::class, 'generate']);
    Route::get('product-ideas/products', [ProductSeoIdeaController::class, 'products']);
    Route::post('product-ideas/{idea}/article', [ProductArticleController::class, 'generate'])->where('idea', $publicId);
    Route::get('product-articles', [ProductArticleController::class, 'index']);
    Route::get('product-articles/{article}', [ProductArticleController::class, 'show']);
    Route::post('product-articles/{article}/retry', [ProductArticleController::class, 'retry']);

    // رفع ایراد صفحه‌ی محصول: محصول‌ها ← بررسی ← پیش‌نمایش ← تأیید و ارسال به سایت
    Route::get('product-fixes/products', [PageProductSeoController::class, 'products']);
    Route::get('product-fixes', [PageProductSeoController::class, 'index']);
    Route::post('product-fixes', [PageProductSeoController::class, 'store']);
    Route::get('product-fixes/{optimization}', [PageProductSeoController::class, 'show'])->where('optimization', $publicId);
    Route::post('product-fixes/{optimization}/approve', [PageProductSeoController::class, 'approve'])->where('optimization', $publicId);

    // آپدیت مقاله‌های گذشته‌ی سایت و مقاله‌های یوتیکس؛ هر دو یک «به‌روزرسانی» می‌سازند که بعد تأیید/رد می‌شود
    Route::get('past-articles', [PastArticlesController::class, 'index']);
    Route::post('past-articles/sync', [PastArticlesController::class, 'sync']);
    Route::get('past-articles/{wordpressArticle}', [PastArticlesController::class, 'show'])->where('wordpressArticle', $publicId);
    Route::post('past-articles/{wordpressArticle}/refresh', [PastArticlesController::class, 'refresh'])->where('wordpressArticle', $publicId);
    Route::post('articles/{article}/refresh', [SearchConsoleInsightsController::class, 'start'])->where('article', $publicId);
    Route::get('article-refreshes/{articleRefresh}', [SearchConsoleInsightsController::class, 'showRefresh'])->where('articleRefresh', $publicId);
    Route::post('article-refreshes/{articleRefresh}/apply', [SearchConsoleInsightsController::class, 'apply'])->where('articleRefresh', $publicId);
    Route::post('article-refreshes/{articleRefresh}/revert', [SearchConsoleInsightsController::class, 'revert'])->where('articleRefresh', $publicId);
    Route::post('article-refreshes/{articleRefresh}/discard', [SearchConsoleInsightsController::class, 'discard'])->where('articleRefresh', $publicId);
});
