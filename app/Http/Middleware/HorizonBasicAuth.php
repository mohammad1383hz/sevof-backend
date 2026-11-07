<?php

namespace App\Http\Middleware;

use Closure;

class HorizonBasicAuth
{
    public function handle($request, Closure $next)
    {
        $username = 'mohammad';
        $password = '09394889350';

        if ($request->getUser() !== $username || $request->getPassword() !== $password) {
            return response('Authentication required', 401, [
                'WWW-Authenticate' => 'Basic',
            ]);
        }

        return $next($request);
    }
}
