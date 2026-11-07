<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleIdeaRelatedLink extends Model
{
    use HasFactory;

protected $table = 'article_idea_related_links';
      protected $guarded = [];


    protected $casts = [
        'match_score' => 'integer',
    ];

    public function articleIdea(): BelongsTo
    {
        return $this->belongsTo(ArticleIdea::class);
    }

    public function wordpressArticle(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class);
    }
}
