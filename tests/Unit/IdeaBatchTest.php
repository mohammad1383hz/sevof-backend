<?php

namespace Tests\Unit;

use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** دسته‌ی ایده‌ی دستی و اتوماسیون هرگز یک شماره نمی‌گیرند؛ دسته‌های قاطیِ قبلی جدا می‌شوند. */
class IdeaBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns'))->setValue(null, []);

        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('idea_generation_batch')->default(0);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('article_ideas', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('website_id');
            $t->unsignedInteger('batch');
            $t->string('source')->default('user');
            $t->string('title');
            $t->timestamps();
        });

        DB::table('sites')->insert(['id' => 1, 'user_id' => 1, 'idea_generation_batch' => 2]);
    }

    public function test_manual_request_after_autopilot_gets_a_new_number(): void
    {
        // اتوماسیون قبلاً دسته‌ی ۳ را ساخته ولی شمارنده‌ی سایت هنوز ۲ است (رفتار قبلی)
        DB::table('article_ideas')->insert(['website_id' => 1, 'batch' => 3, 'source' => 'agent', 'title' => 'ایده‌ی اتوماسیون']);
        $site = Site::find(1);

        $this->assertSame(4, $site->reserveIdeaBatch());
        $this->assertSame(4, $site->idea_generation_batch);
        $this->assertSame(4, (int) DB::table('sites')->where('id', 1)->value('idea_generation_batch'));
        $this->assertSame(5, Site::find(1)->reserveIdeaBatch());
    }

    public function test_repair_moves_autopilot_ideas_out_of_a_mixed_batch(): void
    {
        DB::table('article_ideas')->insert([
            ['website_id' => 1, 'batch' => 3, 'source' => 'agent', 'title' => 'اتوماسیون الف'],
            ['website_id' => 1, 'batch' => 3, 'source' => 'agent', 'title' => 'اتوماسیون ب'],
            ['website_id' => 1, 'batch' => 3, 'source' => 'user', 'title' => 'دستی'],
            ['website_id' => 1, 'batch' => 1, 'source' => 'user', 'title' => 'قدیمی دستی'],
        ]);

        (require base_path('database/migrations/2026_11_05_000002_split_mixed_article_idea_batches.php'))->up();

        $this->assertSame(['دستی'], DB::table('article_ideas')->where('batch', 3)->pluck('title')->all());
        $this->assertSame(['اتوماسیون الف', 'اتوماسیون ب'], DB::table('article_ideas')->where('batch', 4)->orderBy('id')->pluck('title')->all());
        $this->assertSame(1, DB::table('article_ideas')->where('title', 'قدیمی دستی')->value('batch'));
        // سهمیه‌ی پلن رایگان دست نمی‌خورد
        $this->assertSame(2, (int) DB::table('sites')->where('id', 1)->value('idea_generation_batch'));
    }
}
