<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\Auth\EmailAuthService;
use App\Services\Auth\GoogleAuthService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Sign-in is email (+ password, verified by an emailed code) or Google only.
 */
class AuthController extends Controller
{
    public function adminLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->where('is_admin', true)->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            $this->recordLogin(null, 'admin_password', false, $request, 'invalid credentials');

            return response()->json(['message' => 'Invalid admin credentials.'], 422);
        }

        $this->recordLogin($user, 'admin_password', true, $request);

        return $this->tokenResponse($user, 'admin-auth', 'Admin signed in.');
    }

    public function impersonate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $admin = $request->user();

        if (! $admin || ! $admin->is_admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $target = User::where('email', $data['email'])->first();

        if (! $target) {
            return response()->json(['message' => 'No user found with this email.'], 404);
        }

        return $this->impersonationResponse($target, $admin->id, $request);
    }

    public function google(GoogleAuthRequest $request, GoogleAuthService $googleAuthService): JsonResponse
    {
        try {
            $user = $googleAuthService->authenticate($request->validated('id_token'));
        } catch (InvalidArgumentException $exception) {
            $this->recordLogin(null, 'google', false, $request, $exception->getMessage());

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        app(SubscriptionService::class)->ensureFreeSubscription($user);
        $this->recordLogin($user, 'google', true, $request);

        return $this->tokenResponse($user, 'auth', 'Signed in with Google.');
    }

    public function Loginmail(Request $request, EmailAuthService $emailAuthService): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        try {
            $result = $emailAuthService->loginOrRegister($data['email'], $data['password']);
        } catch (InvalidArgumentException $exception) {
            $this->recordLogin(null, 'email', false, $request, $exception->getMessage());

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($result['status'] === 'verification_required') {
            return response()->json([
                'status' => 'verification_required',
                'message' => 'We sent a verification code to your email.',
            ]);
        }

        $user = $result['user'];

        app(SubscriptionService::class)->ensureFreeSubscription($user);
        $this->recordLogin($user, 'email', true, $request);

        return $this->tokenResponse($user, 'auth', 'Signed in successfully.');
    }

    public function verifyEmail(Request $request, EmailAuthService $emailAuthService): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        try {
            $user = $emailAuthService->completeVerification($data['email'], $data['code']);
        } catch (InvalidArgumentException $exception) {
            $this->recordLogin(null, 'email_verify', false, $request, $exception->getMessage());

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        app(SubscriptionService::class)->ensureFreeSubscription($user);
        $this->recordLogin($user, 'email_verify', true, $request);

        return $this->tokenResponse($user, 'auth', 'Email verified. Your account is ready.');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function exchangeImpersonate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $payload = Cache::pull('impersonate:'.$data['code']);

        if (! $payload) {
            return response()->json(['message' => 'The code is invalid or has expired.'], 422);
        }

        $target = User::find($payload['target_id']);

        if (! $target) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return $this->impersonationResponse($target, $payload['admin_id'], $request);
    }

    private function impersonationResponse(User $target, int|string $adminId, Request $request): JsonResponse
    {
        app(SubscriptionService::class)->ensureFreeSubscription($target);

        UserLoginLog::create([
            'user_id' => $target->id,
            'login_method' => 'admin_impersonate',
            'success' => true,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'failure_reason' => "impersonated by admin #{$adminId}",
        ]);

        return $this->tokenResponse($target, 'impersonate', 'Signed in to the user account.');
    }

    private function tokenResponse(User $user, string $tokenName, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'login',
            'message' => $message,
            'token' => $user->createToken($tokenName)->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    private function recordLogin(?User $user, string $method, bool $success, Request $request, ?string $reason = null): void
    {
        UserLoginLog::create([
            'user_id' => $user?->id,
            'login_method' => $method,
            'success' => $success,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'failure_reason' => $reason,
        ]);
    }
}
