<?php

namespace Tests\Unit;

use App\Services\AI\ArticleWriterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

class ReasoningEffortFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::connection()->getPdo()->sqliteCreateFunction('RAND', fn () => mt_rand()); // کوئری کلیدها ORDER BY RAND() دارد
        Schema::create('gapgpt', function (Blueprint $t) {
            $t->id();
            $t->string('api_key');
            $t->string('base_url');
            $t->string('status');
            $t->unsignedInteger('active_requests')->default(0);
            $t->timestamps();
        });
        DB::table('gapgpt')->insert(['api_key' => 'k1', 'base_url' => 'https://ai.test/v1', 'status' => 'active']);
        $this->unsupported()->setValue(null, false);
    }

    private function unsupported(): ReflectionProperty
    {
        return new ReflectionProperty(ArticleWriterService::class, 'reasoningEffortUnsupported');
    }

    public function test_rejected_minimal_retries_with_default_effort_without_disabling_it(): void
    {
        Http::fake(['ai.test/*' => Http::sequence()
            ->push(['error' => ['message' => "Unsupported value: 'reasoning_effort' does not support 'minimal' with this model."]], 400)
            ->push(['choices' => [['message' => ['content' => '{"ok":"yes"}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]),
        ]);

        $result = app(ArticleWriterService::class)->chat(
            model: 'gpt-5-mini', system: 's', user: 'u', schemaName: 'meta_test',
            schema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'string']], 'required' => ['ok'], 'additionalProperties' => false],
            reasoningEffort: 'minimal',
        );

        $this->assertSame(['ok' => 'yes'], $result);
        $efforts = collect(Http::recorded())->map(fn ($pair) => $pair[0]['reasoning_effort'] ?? null)->all();
        $this->assertSame(['minimal', 'low'], $efforts);
        $this->assertFalse($this->unsupported()->getValue()); // بقیه‌ی درخواست‌های این پروسه همچنان 'low' می‌فرستند
    }
}
