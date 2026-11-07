<?php
// app/Services/Auth/EmailVerificationService.php

namespace App\Services\Auth;

use App\Mail\EmailVerificationCodeMail;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class EmailVerificationService
{
    private const CODE_LENGTH = 6;
    private const EXPIRES_IN_MINUTES = 10;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;

    public function generateAndSend(string $email): void
    {
        $last = EmailVerification::where('email', $email)->latest('id')->first();

        if ($last && $last->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            throw new InvalidArgumentException('Please wait a minute before requesting another code.');
        }

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        EmailVerification::create([
            'email' => $email,
            'code' => $code,
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            'attempts' => 0,
        ]);

        Mail::to($email)->send(new EmailVerificationCodeMail($code));
    }

    public function verify(string $email, string $code): void
    {
        $record = EmailVerification::where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (!$record) {
            throw new InvalidArgumentException('No verification code found for this email. Please request a new one.');
        }

        if ($record->expires_at->isPast()) {
            throw new InvalidArgumentException('The verification code has expired.');
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            throw new InvalidArgumentException('Too many attempts. Please request a new code.');
        }

        if (!hash_equals($record->code, $code)) {
            $record->increment('attempts');
            throw new InvalidArgumentException('The code you entered is incorrect.');
        }

        $record->update(['consumed_at' => now()]);
    }
}
