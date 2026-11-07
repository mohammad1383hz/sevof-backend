<?php

namespace App\Http\Middleware;

use App\Models\ApiAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicApiConnection
{
    /**
     * @param  string|null  $mode  'app' = اجرای همان کنترلرهای اپ: سایتِ کلید پارامتر {site} و صاحبش کاربر درخواست می‌شود؛
     *                             مالکیت هر مقاله/ایده/مورد را خود آن کنترلرها با همین سایت چک می‌کنند.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $apiKey = $this->extractKey($request);

        if ($apiKey === null) {
            return response()->json([
                'message' => 'API key is required. Send it via the X-Agent-Key header or as a Bearer token.',
            ], 401);
        }

        $agent = ApiAgent::findActiveFromKey($apiKey);

        if (! $agent) {
            return response()->json(['message' => 'Invalid API credentials.'], 401);
        }

        // سایت واقعیِ متصل به این کلید (نه user)
        $site = $agent->resolveSite();

        if (! $site) {
            return response()->json([
                'message' => 'No site is connected to this API key.',
            ], 422);
        }

        $agent->markUsedNow();

        $request->attributes->set('public_api_agent', $agent);
        $request->attributes->set('public_api_site', $site);

        if ($mode === 'app') {
            $owner = $site->user;

            if (! $owner) {
                return response()->json(['message' => 'No site is connected to this API key.'], 422);
            }

            $request->setUserResolver(fn () => $owner);
            // اول آرایه، چون پارامترها به ترتیب به متد کنترلر (Site $site, ...) داده می‌شوند
            $request->route()->parameters = ['site' => $site] + $request->route()->parameters();
        }

        return $next($request);
    }

    /**
     * کلید را از هدر X-Agent-Key یا از Authorization: Bearer <key> می‌خواند.
     */
    private function extractKey(Request $request): ?string
    {
        $header = trim((string) $request->header('X-Agent-Key'));
        if ($header !== '') {
            return $header;
        }

        $bearer = $request->bearerToken();
        if ($bearer) {
            return trim($bearer);
        }

        return null;
    }
}
