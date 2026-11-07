<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteKeywordTrend extends Model
{
 protected $guarded = [
        'id',
    ];

    protected $casts = [
        'top_queries' => 'array',
        'rising_queries' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
