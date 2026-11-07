<?php

namespace App\Http\Controllers\Wp\Agent;

use App\Http\Controllers\Controller;
use App\Models\SiteConnection;
use App\Services\Wp\WordPressConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * درخواست‌های این کنترلر از پلاگین وردپرس می‌آیند و از میدل‌ور
 * VerifyAgentSignature عبور کرده‌اند؛ اتصال معتبر در
 * $request->attributes->get('site_connection') در دسترس است.
 */
class AgentAuthController extends Controller
{
    public function __construct(
        private readonly WordPressConnectionService $connectionService,
    ) {
    }

    /**
     * پلاگین بعد از دریافت client_id/client_secret/api_key از پنل،
     * یک‌بار این endpoint را برای تایید نهایی اتصال صدا می‌زند.
     */
public function handshake(Request $request): JsonResponse
{
    Log::info('WP Handshake Request', [
        'headers' => $request->headers->all(),
        'body' => $request->all(),
        'raw' => $request->getContent(),
        'ip' => $request->ip(),
    ]);

    /** @var SiteConnection $connection */
    $connection = $request->attributes->get('site_connection');

    Log::info('WP Connection', [
        'exists' => !is_null($connection),
        'id' => $connection?->id,
        'site_id' => $connection?->site_id,
    ]);

    $validated = $request->validate([
        'wp_url' => 'required|url',
        'wp_version' => 'nullable|string|max:50',
        'plugin_version' => 'nullable|string|max:50',
    ]);

    Log::info('WP Validated', $validated);

    $connection = $this->connectionService->markConnected($connection, $validated);

    Log::info('WP Connected', [
        'status' => $connection->status,
    ]);

    return response()->json([
        'message' => 'Connection verified.',
        'data' => [
            'status' => $connection->status,
            'connected_at' => $connection->connected_at,
        ],
    ]);
}

    /**
     * پینگ دوره‌ای از سمت پلاگین برای گزارش سلامت اتصال.
     */
    public function health(Request $request): JsonResponse
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('site_connection');

        $validated = $request->validate([
            'wp_version' => 'nullable|string|max:50',
            'plugin_version' => 'nullable|string|max:50',
        ]);

        $connection = $this->connectionService->recordHealthCheck($connection, 'healthy', $validated);

        return response()->json(['message' => 'Received.', 'data' => ['status' => $connection->last_health_status]]);
    }
}
