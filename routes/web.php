<?php

use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminBehaviorController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminImpersonateController;
use App\Http\Controllers\Admin\AdminPageVisitController;
use App\Http\Controllers\Admin\AdminSalesController;
use App\Http\Controllers\Admin\AdminTicketController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminUserUsageController;
use App\Http\Controllers\Api\AnalyticsEventController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\HorizonAuthController;
use App\Http\Controllers\ReconciliationController;
use App\Services\JustSerpApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;




Route::get('/horizon-login', [HorizonAuthController::class, 'showLoginForm']);
Route::post('/horizon/login', [HorizonAuthController::class, 'login'])->name('horizon.login.submit');
Route::post('/horizon/logout', [HorizonAuthController::class, 'logout']);

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('login.submit');


    Route::middleware('admin.web')->group(function () {
        Route::resource('announcements', \App\Http\Controllers\Admin\AdminAnnouncementController::class)
    ->except('show'); // ← جدید
        Route::post('/users/{user}/impersonate', [AdminImpersonateController::class, 'store'])
    ->name('users.impersonate');
    Route::get('/subscription-exit-surveys', [App\Http\Controllers\Admin\AdminSubscriptionExitSurveyController::class, 'index'])
    ->name('subscription-exit-surveys.index');

Route::get('/subscription-exit-surveys', [App\Http\Controllers\Admin\AdminSubscriptionExitSurveyController::class, 'index'])
    ->name('subscription-exit-surveys.index');

Route::post('/subscription-exit-surveys/users/{user}/discount', [App\Http\Controllers\Admin\AdminSubscriptionExitSurveyController::class, 'assignDiscount'])
    ->name('subscription-exit-surveys.assign-discount');
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::get('/sales', [AdminSalesController::class, 'index'])->name('sales.index');
        Route::get('/activities', [AdminActivityController::class, 'index'])->name('activities.index');
        Route::get('/behavior', [AdminBehaviorController::class, 'index'])->name('behavior.index');
        Route::get('/page-visits', [AdminPageVisitController::class, 'index'])->name('page-visits.index');
        Route::get('tickets', [AdminTicketController::class, 'index'])->name('tickets.index');
Route::get('tickets/{ticket:id}', [AdminTicketController::class, 'show'])->name('tickets.show');
Route::prefix('usage')->name('usage.')->group(function () {
    Route::get('/', [AdminUserUsageController::class, 'index'])->name('index');
    Route::get('/{user}', [AdminUserUsageController::class, 'show'])->name('show');
});
Route::post('tickets/{ticket:id}/reply', [AdminTicketController::class, 'reply'])->name('tickets.reply');
Route::post('tickets/{ticket:id}/close', [AdminTicketController::class, 'close'])->name('tickets.close');
        Route::get('referral-requests', [\App\Http\Controllers\Admin\AdminReferralRequestController::class, 'index'])->name('referral-requests.index');
        Route::get('indexing', [\App\Http\Controllers\Admin\AdminArticleIndexingController::class, 'index'])->name('indexing.index');
        Route::post('indexing/sites/{site:id}/check', [\App\Http\Controllers\Admin\AdminArticleIndexingController::class, 'check'])->name('indexing.check');
        Route::post('indexing/check-all', [\App\Http\Controllers\Admin\AdminArticleIndexingController::class, 'checkAll'])->name('indexing.check-all');
        Route::post('sites/{site:id}/product-currency', [\App\Http\Controllers\Admin\AdminSiteCurrencyController::class, 'update'])->name('sites.product-currency');
        Route::get('content-audit', [\App\Http\Controllers\Admin\AdminContentAuditController::class, 'index'])->name('content-audit.index');
        Route::post('content-audit/sites/{site:id}/audit', [\App\Http\Controllers\Admin\AdminContentAuditController::class, 'audit'])->name('content-audit.audit');
        Route::patch('referral-requests/{referralRequest}', [\App\Http\Controllers\Admin\AdminReferralRequestController::class, 'update'])->name('referral-requests.update');
    });
});

Route::prefix('analytics')->middleware('throttle:120,1')->group(function () {
    Route::post('/events/anonymous', [AnalyticsEventController::class, 'anonymous'])->name('analytics.events.anonymous');
    Route::post('/sessions/start', [AnalyticsEventController::class, 'startSession'])->name('analytics.sessions.start');
    Route::post('/sessions/heartbeat', [AnalyticsEventController::class, 'heartbeat'])->name('analytics.sessions.heartbeat');
    Route::post('/sessions/end', [AnalyticsEventController::class, 'endSession'])->name('analytics.sessions.end');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/events', [AnalyticsEventController::class, 'store'])->name('analytics.events.store');
        Route::post('/events/batch', [AnalyticsEventController::class, 'batch'])->name('analytics.events.batch');
        Route::post('/identify', [AnalyticsEventController::class, 'identify'])->name('analytics.identify');
    });
});




Route::get('/debug/justserp', function (Request $request) {
    $request->validate([
        'api_key' => ['required', 'string'],
        'query' => ['required', 'string'],
        'language' => ['nullable', 'string'],
        'country' => ['nullable', 'string'],
    ]);

    $client = JustSerpApiClient::fromConfig();

    $ref = new \ReflectionClass($client);
    $baseUrl = $ref->getProperty('baseUrl');
    $baseUrl->setAccessible(true);
    $timeout = $ref->getProperty('timeout');
    $timeout->setAccessible(true);

    try {
        $response = Http::withHeaders([
            'X-API-Key' => $request->input('api_key'),
        ])->connectTimeout(10)->timeout($timeout->getValue($client))->get($baseUrl->getValue($client).'/google/search', [
            'query' => $request->input('query'),
            'language' => $request->input('language', 'fa'),
            'country' => $request->input('country', 'ir'),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'ok' => false,
            'exception' => $e->getMessage(),
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $raw = $response->json();

    return $raw;
});
