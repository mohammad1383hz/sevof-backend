<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteKeywordSuggestion extends Model
{
 protected $guarded = [
        'id',
    ];

    protected $casts = [
        'suggestions' => 'array',
        'fetched_at'  => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
