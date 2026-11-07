<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
//مقالات گذشته درساقت میکنه
class WordpressArticle extends Model
{
    use HasPublicToken;
    use HasFactory;
protected $table = 'site_wordpress_articles';
      protected $guarded = [];


    protected $casts = [
        'categories'   => 'array',
        'tags'         => 'array',
        // null = هنوز افزونه ندیده؛ false = پست خود سایت (نه یوتیکس) برای «آپدیت مقاله‌های گذشته»
        'managed'      => 'boolean',
        'editable'     => 'boolean',
        'word_count'   => 'integer',
        'published_at' => 'datetime',
        'modified_at'  => 'datetime',
        'synced_at'    => 'datetime',
        'plugin_synced_at' => 'datetime',
        // خروجی ContentAudit::audit روی متن پست
        'audit'        => 'array',
        'audited_at'   => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
