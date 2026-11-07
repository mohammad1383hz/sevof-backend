<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminWebAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminId = $request->session()->get('admin_id');
        $admin = $adminId ? Admin::find($adminId) : null;

        if (!$admin || !$admin->is_active) {
            $request->session()->forget(['admin_id', 'admin_auth', 'admin']);
            return redirect()->route('admin.login');
        }

        view()->share('currentAdmin', $admin);

        return $next($request);
    }
}
