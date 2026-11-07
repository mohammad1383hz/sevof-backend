<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketReply extends Model
{
    use HasFactory;

      protected $guarded = [];

    protected $casts = [
        'is_staff_reply' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    // رابطه‌ی جدید: هر پاسخ می‌تونه چند تصویر ضمیمه داشته باشه
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketReplyAttachment::class)->oldest();
    }

    public function getAuthorNameAttribute(): ?string
    {
        return $this->is_staff_reply
            ? ($this->admin?->name)
            : ($this->user?->name);
    }
}
