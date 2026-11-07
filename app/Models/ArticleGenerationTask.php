<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleGenerationTask extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['created_at', 'updated_at'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function markFromArticle(?Article $article): void
    {
        if (! $article) {
            return;
        }

        $assetsReady = $article->image_status === 'generated'
            && $article->banner_status === Banner::STATUS_GENERATED
            && $article->banners()->where('status', Banner::STATUS_GENERATED)->exists();

        if ($article->status === Article::STATUS_COMPLETED && $assetsReady) {
            $this->forceFill(['status' => self::STATUS_COMPLETED, 'error_message' => null])->save();
        } elseif ($article->status === Article::STATUS_FAILED) {
            $this->forceFill(['status' => self::STATUS_FAILED, 'error_message' => $article->error_message])->save();
        }
    }
}
