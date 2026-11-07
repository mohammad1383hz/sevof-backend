<?php

namespace App\Jobs\Products;

use App\Models\ProductSeoIdea;
use App\Models\Site;
use App\Services\Products\ProductSeoIdeaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateProductSeoIdeasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    /**
     * @param  int[]  $productIds  محصولاتی که کاربر دستی انتخاب کرده (خالی = انتخاب خودکار)
     */
    public function __construct(
        public Site $site,
        public int $batch,
        public string $prompt = '',
        public array $productIds = [],
    ) {}

    public function handle(ProductSeoIdeaService $service): void
    {
        try {
            app()->bind('current_ai_user', fn () => $this->site->user);

            $this->site->update([
                'idea_generation_status' => 'generating_ideas',
                'idea_generation_error' => null,
            ]);

            $ideas = $service->generate($this->site, $this->prompt, $this->productIds);

            DB::transaction(function () use ($ideas) {
                foreach ($ideas as $idea) {
                    ProductSeoIdea::create([
                        'site_id' => $this->site->id,
                        'batch' => $this->batch,
                        'user_request' => $this->prompt !== '' ? $this->prompt : null,
                        'title' => $idea['title'],
                        'content_style' => $idea['content_style'],
                        'target_category_id' => $idea['target_category_id'],
                        'target_url' => $idea['target_url'],
                        'target_url_title' => $idea['target_url_title'],
                        'categories' => $idea['categories'],
                        'product_ids' => $idea['product_ids'],
                    ]);
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->site->update(['idea_generation_error' => $exception->getMessage()]);
        }
    }
}
