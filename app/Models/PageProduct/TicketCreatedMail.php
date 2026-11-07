<?php
// app/Mail/TicketCreatedMail.php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Ticket $ticket)
    {
    }

    public function build(): self
    {
        return $this->subject('Ticket received #' . $this->ticket->id)
            ->html(
                '<div style="font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right;">'
                . '<p>Hi,</p>'
                . '<p>Your ticket "' . e($this->ticket->subject) . '" has been received.</p>'
                . '<p>Ticket number: #' . $this->ticket->id . '</p>'
                . '<p>We\'ll email you again as soon as our support team replies.</p>'
                . '</div>'
            );
    }
}
