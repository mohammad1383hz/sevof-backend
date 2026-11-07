<?php
// app/Mail/TicketReplyMail.php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply
    ) {
    }

    public function build(): self
    {
        return $this->subject('New reply on ticket #' . $this->ticket->id)
            ->html(
                '<div style="font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right;">'
                . '<p>Hi,</p>'
                . '<p>Your ticket "' . e($this->ticket->subject) . '" has a new reply.</p>'
                . '<p>Ticket number: #' . $this->ticket->id . '</p>'
                . '<div style="background:#f5f5f5;border-radius:8px;padding:12px;margin:12px 0;">'
                . nl2br(e($this->reply->message))
                . '</div>'
                . '<p>Open your dashboard to read the full reply and continue the conversation.</p>'
                . '</div>'
            );
    }
}
