<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class GoogleAuthService
{
    public function authenticate(string $idToken): User
    {
        $clientId = config('seobot.google.client_id');

        if (! $clientId) {
            throw new InvalidArgumentException('Google sign-in is not configured.');
        }

        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Invalid Google token.');
        }

        $payload = $response->json();

        if (($payload['aud'] ?? null) !== $clientId) {
            throw new InvalidArgumentException('This Google token was not issued for this app.');
        }

        $googleId = $payload['sub'] ?? null;
        // An unverified Google email must never be used to take over an existing account.
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $email = $emailVerified && ! empty($payload['email']) ? mb_strtolower($payload['email']) : null;

        if (! $googleId) {
            throw new InvalidArgumentException('Incomplete Google account data.');
        }

        $user = User::query()->where('google_id', $googleId)->first();

        if (! $user && $email) {
            $user = User::query()->where('email', $email)->first();
        }

        if ($user) {
            $user->update([
                'google_id' => $googleId,
                'name' => $payload['name'] ?? $user->name,
                'email' => $email ?? $user->email,
                'avatar' => $payload['picture'] ?? $user->avatar,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            return $user->fresh();
        }

        return User::query()->create([
            'google_id' => $googleId,
            'name' => $payload['name'] ?? null,
            'email' => $email,
            'avatar' => $payload['picture'] ?? null,
            'email_verified_at' => now(),
        ]);
    }
}
