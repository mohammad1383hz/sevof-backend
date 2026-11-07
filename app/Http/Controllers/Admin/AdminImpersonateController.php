<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminImpersonateController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        $adminId = $request->session()->get('admin_id');

        if (!$adminId) {
            abort(403);
        }

        $admin = Admin::find($adminId);

        if (!$admin || !$admin->is_active) {
            abort(403);
        }

        $code = Str::random(40);

        Cache::put('impersonate:' . $code, [
            'target_id' => $user->id,
            'admin_id'  => $admin->id,
        ], now()->addSeconds(60));

        return redirect()->away(
            rtrim(config('app.frontend_url'), '/') . '/impersonate?code=' . $code
        );
    }
}
