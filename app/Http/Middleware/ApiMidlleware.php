<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiMidlleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */

public function handle(Request $request, Closure $next)
{
    $token = $request->header('Token-api');

    if (!$token)
        return response()->json(['message' => 'Token required'], 401);

    $parts = explode('|', $token);

    if (count($parts) !== 2)
        return response()->json(['message' => 'Invalid token format'], 401);

    [$data, $signature] = $parts;

    $expected = hash_hmac('sha256', $data, config('api.secret'));

    if (!hash_equals($expected, $signature))
        return response()->json(['message' => 'Invalid token'], 403);

    $payload = json_decode(base64_decode($data), true);

    if (!isset($payload['t']))
        return response()->json(['message' => 'Token payload missing'], 401);

    // expire بعد ۵ دقیقه
    if ($payload['t'] < time() - 300)
        return response()->json(['message' => 'Token expired'], 401);

    return $next($request);
}


}
