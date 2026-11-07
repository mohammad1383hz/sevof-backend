<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketReplyRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Resources\TicketReplyResource;
use App\Http\Resources\TicketResource;
use App\Mail\TicketCreatedMail;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketReplyAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TicketController extends Controller
{
    /**
     * لیست تیکت‌های کاربر جاری (با امکان فیلتر بر اساس وضعیت)
     * GET /api/v1/tickets
     */
    public function index(Request $request): JsonResponse
    {
        $tickets = Ticket::query()
            ->where('user_id', $request->user()->id)
            ->withCount('replies')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('last_reply_at')
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => [
                'tickets' => TicketResource::collection($tickets->items()),
                'meta' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'total' => $tickets->total(),
                ],
            ],
        ]);
    }

    /**
     * ثبت تیکت جدید همراه با اولین پیام
     * POST /api/v1/tickets
     */
public function store(StoreTicketRequest $request): JsonResponse
{
    $user = $request->user();

    $ticket = DB::transaction(function () use ($request) {
        $ticket = Ticket::create([
            'user_id' => $request->user()->id,
            'subject' => $request->string('subject'),
            'category' => $request->input('category'),
            'priority' => $request->input('priority', 'normal'),
            'status' => 'open',
            'last_reply_at' => now(),
        ]);

        $reply = $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'message' => $request->input('message'),
            'is_staff_reply' => false,
        ]);

        $this->storeAttachments($request, $reply);

        return $ticket;
    });

    $this->sendTicketCreatedMail($request->user(), $ticket);

    return response()->json([
        'success' => true,
        'message' => 'Your ticket has been submitted.',
        'data' => ['ticket' => new TicketResource($ticket->load('replies.user', 'replies.attachments'))],
    ], 201);
}

    /**
     * نمایش جزئیات یک تیکت به همراه پاسخ‌ها
     * GET /api/v1/tickets/{ticket}
     */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You don\'t have access to this ticket.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => ['ticket' => new TicketResource($ticket->load('replies.user', 'replies.attachments'))],
        ]);
    }

    /**
     * افزودن پاسخ جدید به یک تیکت
     * POST /api/v1/tickets/{ticket}/replies
     */
    public function reply(StoreTicketReplyRequest $request, Ticket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You don\'t have access to this ticket.'], 403);
        }

        if ($ticket->isClosed()) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is closed. Please open a new one.',
            ], 422);
        }

        $reply = DB::transaction(function () use ($request, $ticket) {
            $reply = $ticket->replies()->create([
                'user_id' => $request->user()->id,
                'message' => $request->input('message'),
                'is_staff_reply' => false,
            ]);

            $this->storeAttachments($request, $reply);

            return $reply;
        });

        $ticket->update([
            'status' => 'open',
            'last_reply_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your reply was sent.',
            'data' => ['reply' => new TicketReplyResource($reply->load('user', 'attachments'))],
        ], 201);
    }

    /**
     * بستن تیکت توسط کاربر
     * POST /api/v1/tickets/{ticket}/close
     */
    public function close(Request $request, Ticket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You don\'t have access to this ticket.'], 403);
        }

        $ticket->update(['status' => 'closed', 'closed_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket closed.',
            'data' => ['ticket' => new TicketResource($ticket)],
        ]);
    }

    /**
     * ارسال ایمیل «تیکت ثبت شد» - فقط اگر کاربر ایمیل معتبر داشته باشد
     * اگر ارسال ایمیل به هر دلیلی شکست بخورد (SMTP، ایمیل خالی و ...)
     * ثبت تیکت نباید تحت تأثیر قرار بگیرد.
     */
    private function sendTicketCreatedMail(?\App\Models\User $user, Ticket $ticket): void
    {
        if (!$user || empty($user->email)) {
            Log::info('TicketController: user has no email, skipping ticket-created mail', [
                'user_id' => $user?->id,
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        try {
            Mail::to($user->email)->send(new TicketCreatedMail($ticket));
        } catch (Throwable $e) {
            Log::error('TicketController: failed to send ticket-created mail', [
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ذخیره‌ی تصاویر ضمیمه‌ی یک پاسخ روی دیسک و ثبت رکورد در جدول attachments
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
