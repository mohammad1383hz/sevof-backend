<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Model;

class ProductSeoIdea extends Model
{
    use HasPublicToken;
    // protected $appends = ['id'];
    protected $guarded = ['id'];

    protected $casts = [
        'categories' => 'array',
        'product_ids' => 'array',
    ];
    public function internalLinks(): array
{
    return collect($this->categories)
        ->filter(fn ($c) => ! empty($c['url']))
        ->map(fn ($c) => ['url' => $c['url'], 'title' => $c['name']])
        ->unique('url')
        ->values()
        ->all();
}
}
