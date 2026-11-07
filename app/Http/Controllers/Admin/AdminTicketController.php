<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TicketReplyMail;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketReplyAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminTicketController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString() ?: null;

        $tickets = Ticket::query()
            ->with('user:id,name,email')
            ->withCount('replies')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('last_reply_at')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'status' => $status,
        ]);
    }

    public function show(Ticket $ticket)
    {
        $ticket->load([
            'replies.user:id,name',
            'replies.admin:id,name',
            'replies.attachments',
            'user:id,name,email',
        ]);

        return view('admin.tickets.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, Ticket $ticket)
    {
        $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'max:5120'], // حداکثر ۵ مگابایت هر عکس
        ]);

        $admin = Admin::find($request->session()->get('admin_id'));

        if (! $admin) {
            abort(403, 'Admin is not authenticated.');
        }

        $reply = DB::transaction(function () use ($request, $ticket, $admin) {
            $reply = $ticket->replies()->create([
                'admin_id' => $admin->id,
                'message' => $request->input('message'),
                'is_staff_reply' => true,
            ]);

            $this->storeAttachments($request, $reply);

            return $reply;
        });

        $ticket->update([
            'status' => 'answered',
            'last_reply_at' => now(),
        ]);

        $ticket->loadMissing('user');

        $this->sendTicketReplyMail($ticket, $reply);

        return back()->with('success', 'Your reply was sent.');
    }

    public function close(Ticket $ticket)
    {
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);

        return back()->with('success', 'Ticket closed.');
    }

    /**
     * ارسال ایمیل «پاسخ جدید» به کاربر - فقط اگر کاربر ایمیل معتبر داشته باشد
     * اگر ارسال ایمیل به هر دلیلی شکست بخورد، ثبت پاسخ ادمین نباید تحت تأثیر قرار بگیرد.
     */
    private function sendTicketReplyMail(Ticket $ticket, TicketReply $reply): void
    {
        $user = $ticket->user;

        if (!$user || empty($user->email)) {
            Log::info('AdminTicketController: user has no email, skipping reply mail', [
                'user_id' => $user?->id,
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        try {
            Mail::to($user->email)->send(new TicketReplyMail($ticket, $reply));
        } catch (Throwable $e) {
            Log::error('AdminTicketController: failed to send reply mail', [
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'reply_id' => $reply->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ذخیره‌ی تصاویر ضمیمه‌ی پاسخ ادمین روی دیسک و ثبت رکورد در جدول attachments
     */
    private function storeAttachments(Request $request, TicketReply $reply): void
    {
        $files = $request->file('images', []);

        if (empty($files)) {
            return;
        }

        foreach ($files as $file) {
            if (!$file->isValid()) {
                continue;
            }

            $path = $file->store('ticket-attachments', 'public');

            TicketReplyAttachment::create([
                'ticket_reply_id' => $reply->id,
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }
}
