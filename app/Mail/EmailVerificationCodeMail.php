<?php
// app/Mail/EmailVerificationCodeMail.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code)
    {
    }

    public function build(): self
    {
        return $this->subject('Your '.config('app.name', 'Sevof').' verification code')
            ->view('emails.verification-code')
            ->with(['code' => $this->code]);
    }
}
