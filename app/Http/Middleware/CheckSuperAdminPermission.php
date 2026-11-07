<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSuperAdminPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
 $admin = $request->user(); 
 
        if (!$admin || !($admin instanceof \App\Models\SuperAdmin)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: admins only'
            ], 401);
        }
// dd($admin->permissions);
        if (!$admin || !$admin->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'You don\'t have permission'
            ], 403);
        }

        return $next($request);
    }
}
