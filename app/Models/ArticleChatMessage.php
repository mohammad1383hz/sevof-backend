<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleChatMessage extends Model
{
   protected $guarded = [
        'id',
    ];

    protected $casts = [
        'applied' => 'boolean',
        'target_id' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
