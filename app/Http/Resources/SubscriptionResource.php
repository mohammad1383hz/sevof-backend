<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'tokens_used' => $this->tokens_used,
            'token_limit' => $this->plan->token_limit,
            'remaining_tokens' => $this->remainingTokens(),
            'usage_percentage' => $this->tokenUsagePercentage(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'plan' => [
                'id' => $this->plan->token,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
                'token_limit' => $this->plan->token_limit,
                'site_limit' => $this->plan->site_limit,
                'article_limit' => $this->plan->article_limit,
                'is_free' => $this->plan->is_free,
            ],
        ];
    }
}
