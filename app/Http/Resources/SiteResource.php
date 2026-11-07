<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->token,
            'domain' => $this->domain,
            'url' => $this->url,
            'status' => $this->status,
            'niche' => $this->niche,
            'summary' => $this->summary,
'is_wordpress' => (bool) $this->is_wordpress,
'is_shop' => (bool) $this->is_shop,

'is_woocommerce' => (bool) $this->is_woocommerce,
            'keywords' => $this->keywords,
            'crawled_pages' => $this->when(
                $request->boolean('include_pages'),
                $this->crawled_pages,
            ),
            'crawled_pages_count' => is_array($this->crawled_pages) ? count($this->crawled_pages) : 0,
            'ai_response' => $this->when(
                $request->boolean('include_ai'),
                $this->ai_response,
            ),
            'error_message' => $this->error_message,
            'analyzed_at' => $this->analyzed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // فیلدهای مرحله دوم (شناسایی کلمه کلیدی نهایی با گوگل ترندز + AI)
            'final_keyword' => $this->final_keyword,
            'final_title' => $this->final_title,
            'keyword_analysis_status' => $this->keyword_analysis_status,
            'keyword_analysis_error' => $this->keyword_analysis_error,
'idea_generation_batch' => $this->idea_generation_batch,
            // فیلدهای مرحله تولید ایده مقاله (۴ مرحله‌ای: connecting_sources / analyzing_keywords / generating_ideas / scoring_ideas / done)
            'idea_generation_status' => $this->idea_generation_status,
            'idea_generation_error' => $this->idea_generation_error,
        ];
    }
}
