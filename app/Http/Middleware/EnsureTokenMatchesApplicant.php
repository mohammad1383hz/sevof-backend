<?php
// app/Http/Middleware/EnsureTokenMatchesApplicant.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTokenMatchesApplicant
{
    public function handle(Request $request, Closure $next)
    {
        $applicant = $request->user(); // از توکن sanctum

        if (!$applicant) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your national ID first.',
            ], 401);
        }

        // اگر national_id در body یا route پارامتر آمده، باید با صاحب توکن یکی باشد
        $requestNationalId = $request->input('national_id') ?? $request->route('national_id');

        if ($requestNationalId && $requestNationalId !== $applicant->national_id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}