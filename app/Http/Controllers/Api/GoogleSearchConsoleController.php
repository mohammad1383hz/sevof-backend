<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSearchConsoleDataJob;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\Site;
use App\Models\SiteSearchConsoleProperty;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class GoogleSearchConsoleController extends Controller
{
    public function connect(Request $request, Site $site, GoogleSearchConsoleService $service): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate(['return_to' => 'nullable|string|in:automation']);

        return response()->json([
            'authorization_url' => $service->authorizationUrl($request->user(), $site, $validated['return_to'] ?? null),
        ]);
    }

    public function callback(Request $request, GoogleSearchConsoleService $service): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string',
                'state' => 'required|string',
            ]);

            ['site' => $site, 'returnTo' => $returnTo] = $service->completeAuthorization($validated['code'], $validated['state']);

            return redirect()->away($returnTo === 'automation'
                ? $this->frontendUrl().'/sites/'.$site->id.'/dashboard?gsc=connected'
                : $this->frontendUrl().'/dashboard?site='.$site->id.'&gsc=connected');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->away($this->frontendUrl().'/dashboard?gsc=error');
        }
    }

    public function status(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = GoogleSearchConsoleConnection::where('user_id', $request->user()->id)->first();
        $property = SiteSearchConsoleProperty::where('site_id', $site->id)->first();

        return response()->json([
            'connection' => $connection ? [
                'status' => $connection->status,
                'last_error' => $connection->last_error,
                'email' => $connection->google_email,
            ] : null,
            'property' => $property ? $this->propertyPayload($property) : null,
        ]);
    }

    public function properties(Request $request, Site $site, GoogleSearchConsoleService $service): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = GoogleSearchConsoleConnection::where('user_id', $request->user()->id)
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            return response()->json(['message' => 'Google Search Console is not connected.'], 422);
        }

        return response()->json([
            'properties' => $service->properties($connection),
        ]);
    }

    public function selectProperty(Request $request, Site $site, GoogleSearchConsoleService $service): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'property_url' => 'required|string|max:2048',
        ]);

        $property = $service->assignProperty($request->user(), $site, $validated['property_url']);

        // بلافاصله بعد از انتخاب، داده گرفته می‌شود تا کاربر منتظر دکمه‌ی دستی نماند
        $property->update(['sync_status' => 'queued', 'last_sync_error' => null]);
        SyncSearchConsoleDataJob::dispatch($property->id);

        return response()->json([
            'property' => $this->propertyPayload($property),
        ]);
    }

    public function sync(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $property = SiteSearchConsoleProperty::where('site_id', $site->id)->first();
        if (! $property) {
            return response()->json(['message' => 'Select a Search Console property first.'], 422);
        }

        $property->update([
            'sync_status' => 'queued',
            'last_sync_error' => null,
        ]);
        SyncSearchConsoleDataJob::dispatch($property->id);

        return response()->json([
            'message' => 'Search Console data sync started.',
            'property' => $this->propertyPayload($property->fresh()),
        ], 202);
    }

    public function queries(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $property = SiteSearchConsoleProperty::where('site_id', $site->id)->first();
        if (! $property) {
            return response()->json(['queries' => []]);
        }

        $queries = DB::table('search_console_query_metrics')
            ->where('site_search_console_property_id', $property->id)
            ->orderByDesc('impressions')
            ->orderByDesc('clicks')
            ->limit(100)
            ->get()
            ->map(fn ($metric) => [
                'query' => $metric->query,
                'page' => $metric->page,
                'clicks' => (int) $metric->clicks,
                'previous_clicks' => $metric->previous_clicks !== null ? (int) $metric->previous_clicks : null,
                'impressions' => (int) $metric->impressions,
                'previous_impressions' => $metric->previous_impressions !== null ? (int) $metric->previous_impressions : null,
                'ctr' => (float) $metric->ctr,
                'position' => $metric->position !== null ? (float) $metric->position : null,
                'previous_position' => $metric->previous_position !== null ? (float) $metric->previous_position : null,
                'trend_percentage' => $metric->trend_percentage !== null ? (int) $metric->trend_percentage : null,
                'start_date' => $metric->start_date,
                'end_date' => $metric->end_date,
                'fetched_at' => $metric->fetched_at,
            ]);

        return response()->json([
            'queries' => $queries,
        ]);
    }

    public function disconnect(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        SiteSearchConsoleProperty::where('site_id', $site->id)->delete();

        return response()->json([
            'message' => 'The Search Console property was removed from this site.',
        ]);
    }

    /** Disconnect the user's Google account (for all their sites) so they can connect a different one */
    public function disconnectAccount(Request $request, Site $site, GoogleSearchConsoleService $service): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = GoogleSearchConsoleConnection::where('user_id', $request->user()->id)->first();
        if ($connection) {
            $service->disconnectAccount($connection);
        }

        return response()->json([
            'message' => 'Your Google account was disconnected.',
        ]);
    }

    protected function propertyPayload(SiteSearchConsoleProperty $property): array
    {
        return [
            'id' => $property->id,
            'property_url' => $property->property_url,
            'permission_level' => $property->permission_level,
            'sync_status' => $property->sync_status,
            'last_synced_at' => $property->last_synced_at?->toISOString(),
            'last_sync_error' => $property->last_sync_error,
        ];
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }

    protected function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url') ?: config('app.url'), '/');
    }
}
