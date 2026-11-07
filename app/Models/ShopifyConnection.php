<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && $this->shop_domain && $this->access_token;
    }

    /** Public storefront origin (primary domain), e.g. https://shop.example.com */
    public function storefrontUrl(): string
    {
        return rtrim($this->shop_url ?: 'https://'.$this->shop_domain, '/');
    }
}
