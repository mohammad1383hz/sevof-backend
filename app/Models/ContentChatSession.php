<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ContentChatSession extends Model
{
   public const KIND_ANALYSIS = 'analysis';
public const KIND_CHAT = 'chat';
public const KIND_PRODUCT = 'product';
    protected $guarded = ['id', 'token'];

    protected static function booted(): void
    {
        static::creating(function (ContentChatSession $session): void {
            if (!$session->token) {
                $session->token = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ContentChatMessage::class);
    }
}
