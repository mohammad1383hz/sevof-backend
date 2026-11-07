<?php

namespace App\Http\Middleware;

use App\Models\AgentRequestLog;
use App\Models\SiteConnection;
use App\Services\Wp\AgentSignatureService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * تمام درخواست‌های ورودی از پلاگین وردپرس باید از این میدل‌ور عبور کنند.
 * بررسی می‌کند: X-Agent-Key معتبر است، X-Timestamp منقضی نشده،
 * X-Signature صحیح است و درخواست تکراری (Replay) نیست.
 */
class VerifyAgentSignature
{
    public function __construct(
        private readonly AgentSignatureService $signer,
    ) {
    }

public function handle(Request $request, Closure $next): Response
{
    Log::info('===== VerifyAgentSignature =====');

    $apiKey = $request->header('X-Agent-Key');
    $timestamp = $request->header('X-Timestamp');
    $signature = $request->header('X-Signature');

    Log::info('Headers', [
        'api_key' => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $signature,
        'path' => $request->path(),
        'method' => $request->method(),
    ]);

    if (! $apiKey || ! $timestamp || ! $signature) {
        Log::warning('Missing authentication headers');
        return $this->reject($request, null, 401, 'Authentication headers are incomplete.');
    }

    $connection = SiteConnection::where('api_key', $apiKey)->first();

    Log::info('Connection lookup', [
        'found' => ! is_null($connection),
        'site_id' => $connection?->site_id,
        'status' => $connection?->status,
    ]);

    if (! $connection) {
        Log::warning('Connection not found');
        return $this->reject($request, null, 401, 'Invalid authentication key.');
    }

    if ($connection->status === SiteConnection::STATUS_DISCONNECTED) {
        Log::warning('Connection disconnected');
        return $this->reject($request, $connection, 403, 'This connection has been disconnected.');
    }

    $body = $request->getContent() ?: '';

    Log::info('Request Body', [
        'body' => $body,
    ]);

 $verified = $this->signer->verify(
    $connection,
    $timestamp,
    $signature,
    $body,
    $request->method(),
    $request->path()
);

    Log::info('Signature Verify', [
        'verified' => $verified,
    ]);

    if (! $verified) {
        Log::warning('Signature invalid');
        return $this->reject($request, $connection, 401, 'The request signature is invalid or expired.');
    }

    $replayKey = 'agent_signature:' . hash('sha256', $signature);

    Log::info('Replay Key', [
        'key' => $replayKey,
        'exists' => Cache::has($replayKey),
    ]);

    if (Cache::has($replayKey)) {
        Log::warning('Replay detected');
        return $this->reject($request, $connection, 401, 'This request was already processed (replay).');
    }

    $tolerance = (int) config('wordpress_agent.timestamp_tolerance_seconds', 300);

    Cache::put($replayKey, true, $tolerance);

    Log::info('Replay key stored');

    $request->attributes->set('site_connection', $connection);

    Log::info('Calling controller...');

    $response = $next($request);

    Log::info('Controller finished', [
        'status' => $response->getStatusCode(),
    ]);

    $this->logRequest($request, $connection, $response->getStatusCode());

    return $response;
}

    private function reject(Request $request, ?SiteConnection $connection, int $status, string $message): Response
    {
        $this->logRequest($request, $connection, $status, $message);

        return response()->json(['message' => $message], $status);
    }

    private function logRequest(Request $request, ?SiteConnection $connection, int $statusCode, ?string $error = null): void
    {
        AgentRequestLog::create([
            'site_id' => $connection?->site_id,
            'api_key' => $request->header('X-Agent-Key'),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status_code' => $statusCode,
            'ip' => $request->ip(),
            'error_message' => $error,
            'created_at' => now(),
        ]);
    }
}
