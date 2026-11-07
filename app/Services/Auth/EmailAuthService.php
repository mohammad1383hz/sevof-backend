<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class EmailAuthService
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService
    ) {
    }

    /**
     * Existing user: check the password; unverified emails get a fresh code.
     * New email: create the account and send a verification code.
     *
     * @return array{status: 'login'|'verification_required', user: User}
     */
    public function loginOrRegister(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $user = User::where('email', $email)->first();

        if ($user) {
            if (! $user->password || ! Hash::check($password, $user->password)) {
                throw new InvalidArgumentException('Incorrect email or password.');
            }

            if (! $user->email_verified_at) {
                $this->emailVerificationService->generateAndSend($email);

                return ['status' => 'verification_required', 'user' => $user];
            }

            return ['status' => 'login', 'user' => $user];
        }

        $user = User::create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->emailVerificationService->generateAndSend($email);

        return ['status' => 'verification_required', 'user' => $user];
    }

    public function completeVerification(string $email, string $code): User
    {
        $email = mb_strtolower(trim($email));
        $user = User::where('email', $email)->first();

        if (! $user) {
            throw new InvalidArgumentException('No account found with this email.');
        }

        $this->emailVerificationService->verify($email, $code);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user->fresh();
    }
}
