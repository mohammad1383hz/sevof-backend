<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->token,
            'name'                => $this->name,
            'slug'                => $this->slug,
            'description'         => $this->description,
            'token_limit'         => $this->token_limit,
            'credit'              => $this->credit,
            'site_limit'          => $this->site_limit,
            'article_limit'       => $this->article_limit,
            'is_active'           => $this->is_active,
            'is_free'             => $this->is_free,
            // all amounts are integer minor units (cents) of `currency`
            'currency'            => strtolower((string) config('services.stripe.currency', 'usd')),
            'price'               => $this->price,
            'discount_percentage' => $this->discount_percentage ?? 0, 
            'final_price'         => $this->final_price,         
            // price per purchasable period (1/3/6 months); price/final_price above are monthly
            'periods'             => array_map(fn (int $months) => [
                'months'              => $months,
                'discount_percentage' => $this->discountPercentFor($months),
                'original_price'      => $this->originalPriceFor($months),
                'final_price'         => $this->priceFor($months),
                'monthly_price'       => (int) round($this->priceFor($months) / $months),
                'credit'              => $this->creditFor($months),
            ], $this->availablePeriods()),
            'features'            => $this->features,
        ];
    }
}
