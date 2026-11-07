<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductArticleSection extends Model
{
        use HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'subheadings' => 'array',
        'key_points' => 'array',
        'product_ids' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(ProductArticle::class, 'product_article_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProductArticleAssignment::class, 'assignment_id');
    }
}
