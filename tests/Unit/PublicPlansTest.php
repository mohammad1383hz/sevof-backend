<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicPlansTest extends TestCase
{
    public function test_plans_with_period_prices_are_public(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->string('name');
            $t->string('slug');
            $t->text('description')->nullable();
            foreach (['token_limit', 'site_limit', 'article_limit', 'price', 'discount_percentage', 'quarterly_discount_percentage', 'semiannual_discount_percentage'] as $c) {
                $t->integer($c)->nullable();
            }
            $t->decimal('credit')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_free')->default(false);
            $t->json('features')->nullable();
            $t->timestamps();
        });
        DB::table('plans')->insert([
            ['name' => 'Pro', 'slug' => 'pro', 'price' => 1_000_000, 'discount_percentage' => 10, 'quarterly_discount_percentage' => 20, 'semiannual_discount_percentage' => 30, 'credit' => 500, 'is_active' => true, 'is_free' => false],
            ['name' => 'Old', 'slug' => 'old', 'price' => 5, 'discount_percentage' => 0, 'quarterly_discount_percentage' => 0, 'semiannual_discount_percentage' => 0, 'credit' => 1, 'is_active' => false, 'is_free' => false],
        ]);

        $plans = $this->getJson('/api/v1/public/plans')->assertOk()->json('data');

        $this->assertCount(1, $plans); // پلن غیرفعال نمایش داده نمی‌شود
        $this->assertSame([1, 3, 6], array_column($plans[0]['periods'], 'months'));
        $this->assertSame(4_200_000, $plans[0]["periods"][2]["final_price"]); // ۶ ماه × ۱٬۰۰۰٬۰۰۰ − ۳۰٪
    }
}
