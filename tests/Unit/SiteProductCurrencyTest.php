<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\AdminSiteCurrencyController;
use App\Models\Site;
use App\Services\Products\SiteProductService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** واحد قیمت محصولات: پیش‌فرض همه مثل قبل (تومان)، ادمین می‌تواند یک سایت را ریال کند. */
class SiteProductCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Eloquent ستون‌های جدول را بین تست‌ها نگه می‌دارد؛ جدول sites تست‌های قبلی ستون product_currency ندارد
        (new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns'))->setValue(null, []);

        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->string('domain')->nullable();
            $t->string('product_currency', 3)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('site_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->string('slug');
            $t->decimal('price', 15, 2)->nullable();
            $t->string('currency')->nullable();
            $t->timestamps();
        });

        DB::table('sites')->insert([['id' => 1, 'user_id' => 1, 'domain' => 'rial.ir'], ['id' => 2, 'user_id' => 2, 'domain' => 'toman.ir']]);
        DB::table('site_products')->insert([
            ['site_id' => 1, 'slug' => 'a', 'price' => 1250000, 'currency' => null],
            ['site_id' => 1, 'slug' => 'b', 'price' => 990000, 'currency' => 'IRT'],
            ['site_id' => 2, 'slug' => 'c', 'price' => 50000, 'currency' => null],
        ]);
    }

    public function test_sites_without_a_choice_stay_as_they_were(): void
    {
        app(SiteProductService::class)->applySiteCurrency(Site::find(2));

        $this->assertNull(DB::table('site_products')->where('slug', 'c')->value('currency'));
    }

    public function test_admin_sets_one_site_to_rial_and_only_its_products_change(): void
    {
        $response = app(AdminSiteCurrencyController::class)->update(
            Request::create('/admin/sites/1/product-currency', 'POST', ['currency' => 'IRR']),
            Site::find(1),
            app(SiteProductService::class),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('IRR', Site::find(1)->product_currency);
        $this->assertSame(['IRR'], DB::table('site_products')->where('site_id', 1)->distinct()->pluck('currency')->all());
        $this->assertNull(DB::table('site_products')->where('site_id', 2)->value('currency'));

        // محصولی که بعداً با sync بیاید هم ریال می‌شود
        DB::table('site_products')->insert(['site_id' => 1, 'slug' => 'd', 'price' => 10, 'currency' => null]);
        app(SiteProductService::class)->applySiteCurrency(Site::find(1));
        $this->assertSame('IRR', DB::table('site_products')->where('slug', 'd')->value('currency'));
    }

    public function test_only_rial_or_toman_is_accepted(): void
    {
        $this->expectException(ValidationException::class);

        app(AdminSiteCurrencyController::class)->update(
            Request::create('/admin/sites/1/product-currency', 'POST', ['currency' => 'USD']),
            Site::find(1),
            app(SiteProductService::class),
        );
    }
}
