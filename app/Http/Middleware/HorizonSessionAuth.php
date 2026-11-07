<?php

namespace App\Http\Middleware;

use Closure;

class HorizonSessionAuth
{
    public function handle($request, Closure $next)
    {
        if (!session('horizon_auth')) {
            return redirect('/horizon-login');
        }

        return $next($request);
    }
}
