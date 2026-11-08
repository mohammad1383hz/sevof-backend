<?php

use App\Http\Middleware\AdminWebAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/wordpress.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // There is no Blade login page: API guests get 401, others go to the panel's login.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : rtrim((string) config('app.front_url'), '/').'/login');

        $middleware->alias([
            'applicant.match' => \App\Http\Middleware\EnsureTokenMatchesApplicant::class,
            'agent.signature' => \App\Http\Middleware\VerifyAgentSignature::class,
            'horizon.auth' => \App\Http\Middleware\HorizonBasicAuth::class,
            'horizon.session' => \App\Http\Middleware\HorizonSessionAuth::class,
            'admin.web' => AdminWebAuth::class,
            'is_admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients always get JSON (e.g. 401), never a redirect to a non-existent login page.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->report(function (\Throwable $e): void {
            Log::error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        })->stop();
    })
    ->create();
