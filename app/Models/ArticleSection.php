<?php
// app/Models/ArticleSection.php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleSection extends Model
{
    use HasPublicToken;
    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_FAILED = 'failed';

 protected $guarded = [
        'id',
    ];

    protected $casts = [
        'subheadings' => 'array',
        'key_points' => 'array',
        'related_links_to_insert' => 'array',
        'insert_internal_link' => 'boolean',
        'insert_brand_mention' => 'boolean',
    ];
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
