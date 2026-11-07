<?php

/**
 * این خطوط رو داخل فایل routes/api.php (یا هرجایی که routeهای
 * sites/{site}/articles/{article}/... موجودت تعریف شده) اضافه کن،
 * همون middleware/prefix گروهی که برای بقیه‌ی route های article استفاده می‌کنی رو نگه دار.
 */

use App\Http\Controllers\ArticleChatController;
use App\Http\Controllers\ArticleController;
use Illuminate\Support\Facades\Route;

Route::prefix('sites/{site}/articles/{article}')->group(function () {
    // چت
    Route::get('chat', [ArticleChatController::class, 'index']);
    Route::post('chat', [ArticleChatController::class, 'store']);

    // ویرایش دستی
    Route::patch('/', [ArticleController::class, 'updateField']);
    Route::patch('sections/{section}', [ArticleController::class, 'updateSection']);
    Route::patch('faq/{index}', [ArticleController::class, 'updateFaq']);
});
