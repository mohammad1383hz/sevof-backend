<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ContentChatMessage extends Model
{
    use HasPublicToken;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entity' => 'array',
            'options' => 'array',
            'answered' => 'boolean',
            'batch' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ContentChatSession::class, 'content_chat_session_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function researchSession(): BelongsTo
    {
        return $this->belongsTo(ContentResearchSession::class, 'content_research_session_id');
    }
}
