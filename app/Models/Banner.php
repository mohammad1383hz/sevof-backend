<?php
// app/Models/Banner.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Banner extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_GENERATING = 'generating';
    const STATUS_GENERATED = 'generated';
    const STATUS_FAILED = 'failed';

     protected $guarded = [];


    // یک بنر متعلق به یک سایته (برای لوگو/اسم برندش ساخته شده)
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    // ولی می‌تونه هم‌زمان روی چند مقاله attach بشه (many-to-many)
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_banner')->withTimestamps();
    }
}
