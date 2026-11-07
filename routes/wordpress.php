<?php

use App\Http\Controllers\Wp\Agent\AgentArticleController;
use App\Http\Controllers\Wp\Agent\AgentAuthController;
use App\Http\Controllers\Wp\Agent\AgentDashboardController;
use App\Http\Controllers\Wp\ArticlePublishController;
use App\Http\Controllers\Wp\ProductArticleWordPressController;
use App\Http\Controllers\Wp\WordPressConnectionController;
use App\Http\Controllers\Shopify\ShopifyConnectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 1) روت‌های پنل (سمت کاربر لاگین‌شده — auth:sanctum)
|--------------------------------------------------------------------------
| این بلاک را داخل همان گروه auth:sanctum موجود در routes/api.php
| (کنار بقیه‌ی روت‌های sites/{site}/...) قرار بده.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('v1/sites/{site}/shopify')->group(function () {
        Route::get('/', [ShopifyConnectionController::class, 'show']);
        Route::post('/install', [ShopifyConnectionController::class, 'install'])->middleware('throttle:10,1');
        Route::post('/test', [ShopifyConnectionController::class, 'test']);
        Route::post('/disconnect', [ShopifyConnectionController::class, 'disconnect']);
    });
    Route::prefix('v1/sites/{site}/wordpress')->group(function () {
        Route::get('/', [WordPressConnectionController::class, 'show']);
        Route::post('/connect', [WordPressConnectionController::class, 'connect']);
        Route::post('/reconnect', [WordPressConnectionController::class, 'reconnect']);
        Route::post('/disconnect', [WordPressConnectionController::class, 'disconnect']);
        Route::post('/test', [WordPressConnectionController::class, 'test']);
    });


Route::prefix('v1/sites/{site}/product-articles/{article}/publish')->group(function () {
    Route::post('/', [ProductArticleWordPressController::class, 'syncToWordPress']);
    Route::delete('/', [ProductArticleWordPressController::class, 'destroyOnWordPress']);
    Route::post('/sync', [ProductArticleWordPressController::class, 'syncStatus']);
});
    Route::prefix('sites/{site}/articles/{article}/publish')->group(function () {
        Route::post('/', [ArticlePublishController::class, 'publish']);
        Route::put('/', [ArticlePublishController::class, 'update']);
        Route::delete('/', [ArticlePublishController::class, 'destroy']);
        Route::post('/sync', [ArticlePublishController::class, 'syncStatus']);
    });
});

/*
| Shopify OAuth + webhooks (browser redirects / Shopify servers; verified by HMAC, not Sanctum).
*/
Route::get('v1/shopify/callback', [ShopifyConnectionController::class, 'callback']);
Route::get('v1/shopify/entry', [ShopifyConnectionController::class, 'entry']);
Route::post('shopify/webhooks', [ShopifyConnectionController::class, 'webhook']);

/*
|--------------------------------------------------------------------------
| 2) روت‌های پلاگین وردپرس (سمت پلاگین — بدون sanctum، با امضای HMAC)
|--------------------------------------------------------------------------
| این‌ها به‌صورت مستقل ثبت می‌شوند، نه داخل گروه auth:sanctum.
| میدل‌ورها: agent.signature (بررسی X-Agent-Key/X-Timestamp/X-Signature)
| و agent.throttle (Rate Limit جدا از throttle پیش‌فرض لاراول).
*/
Route::prefix('agent')->middleware(['agent.signature'])->group(function () {
    Route::post('connect/handshake', [AgentAuthController::class, 'handshake']);
    Route::post('health', [AgentAuthController::class, 'health']);

    Route::get('dashboard', [AgentDashboardController::class, 'index']);
    Route::get('keywords', [AgentDashboardController::class, 'keywords']);
    Route::get('entities', [AgentDashboardController::class, 'entities']);
Route::get('subscription', [AgentDashboardController::class, 'subscription']);
    Route::get('product-articles', [AgentDashboardController::class, 'productArticles']);

    Route::get('articles', [AgentArticleController::class, 'index']);
    Route::get('articles/{article}', [AgentArticleController::class, 'show']);
});
