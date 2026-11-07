<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteProduct extends Model
{
    protected $guarded = [];


    protected $casts = [
        'category_ids' => 'array',
        'images' => 'array',
        'raw_data' => 'array',
        'price' => 'decimal:2',
        'regular_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_manual' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SiteProductCategory::class, 'category_id');
    }
}
