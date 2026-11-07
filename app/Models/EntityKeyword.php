<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityKeyword extends Model
{
   protected $guarded = [
        'id',
    ];

    protected $casts = [
        'source_variants' => 'array',
        'total_relevance' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
