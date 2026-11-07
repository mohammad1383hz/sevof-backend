<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiAgent;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiAgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agents = $request->user()->apiAgents()
            ->with('site:id,token,domain')
            ->latest()
            ->get(['id', 'token', 'site_id', 'name', 'key_last_four', 'last_used_at', 'revoked_at', 'created_at']);

        return response()->json(['data' => $agents]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // سایت باید متعلق به همین کاربر و حذف‌نشده باشد
            // توکن سایت
            'site_id' => [
                'required',
                'string',
                Rule::exists('sites', 'token')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $site = Site::where('user_id', $user->id)->where('token', $validated['site_id'])->firstOrFail();

        [$agent, $plainKey] = ApiAgent::issueFor($user, $validated['name'], $site);

        return response()->json([
            'id' => $agent->token,
            'name' => $agent->name,
            'site' => ['id' => $site->token, 'domain' => $site->domain],
            // فقط همین یک بار برگردانده می‌شود
            'api_key' => $plainKey,
            'warning' => 'This key is shown only once. Store it securely.',
        ], 201);
    }

    public function destroy(Request $request, ApiAgent $apiAgent): JsonResponse
    {
        abort_unless($apiAgent->user_id === $request->user()->id, 404);

        $apiAgent->revoke();

        return response()->json(['message' => 'API key revoked.']);
    }
}
