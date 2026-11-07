<?php

namespace Tests\Unit;

use App\Jobs\AgentWP\RunAgentArticleGenerationJob;
use App\Jobs\ApplyArticleRefreshJob;
use App\Jobs\CheckArticleIndexingJob;
use App\Jobs\GenerateArticleImageJob;
use App\Jobs\GenerateArticleRefreshJob;
use App\Jobs\GenerateArticleSectionJob;
use App\Jobs\Products\RefreshSiteProductsJob;
use App\Jobs\Products\SyncSiteProductsJob;
use App\Jobs\RunContentResearchJob;
use App\Jobs\SyncSearchConsoleDataJob;
use ReflectionClass;
use Tests\TestCase;

class BackgroundQueueConfigTest extends TestCase
{
    private function supervisors(): array
    {
        return (require config_path('horizon.php'))['environments']['production'];
    }

    public function test_product_jobs_go_to_their_own_queue_and_worker(): void
    {
        foreach ([new RefreshSiteProductsJob(1), new SyncSiteProductsJob(1)] as $job) {
            $this->assertSame(['redis-background', 'products'], [$job->connection, $job->queue]);
        }

        $products = $this->supervisors()['supervisor-products'];
        $this->assertSame(['redis-background', ['products']], [$products['connection'], $products['queue']]);
        $this->assertGreaterThanOrEqual(2, $this->supervisors()['supervisor-default']['minProcesses']);
    }

    public function test_separate_queues_have_long_enough_retry_after(): void
    {
        $supervisors = $this->supervisors();

        // اگر timeout یک job از retry_after بیشتر باشد، worker دیگری همان job را هم‌زمان دوباره اجرا می‌کند
        foreach ([
            'supervisor-products' => [RefreshSiteProductsJob::class, SyncSiteProductsJob::class],
            'supervisor-background' => [
                RunContentResearchJob::class, SyncSearchConsoleDataJob::class, CheckArticleIndexingJob::class,
                GenerateArticleRefreshJob::class, ApplyArticleRefreshJob::class, GenerateArticleImageJob::class,
                GenerateArticleSectionJob::class, RunAgentArticleGenerationJob::class,
            ],
        ] as $name => $jobs) {
            $supervisor = $supervisors[$name];
            $connection = config('queue.connections.'.$supervisor['connection']);
            $this->assertNotNull($connection, $name);

            foreach ($jobs as $job) {
                $class = new ReflectionClass($job);
                $timeout = ($class->hasProperty('timeout') ? $class->getProperty('timeout')->getDefaultValue() : null) ?? $supervisor['timeout'];
                $this->assertLessThan($connection['retry_after'], $timeout, $job);
                $this->assertLessThanOrEqual($supervisor['timeout'], $timeout, $job);
            }
        }
    }
}
