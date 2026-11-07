<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->string('shop_domain')->index();      // my-store.myshopify.com
            $table->text('access_token');                // encrypted cast
            $table->string('scopes')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('shop_url')->nullable();      // primary storefront domain
            $table->string('blog_id')->nullable();       // gid://shopify/Blog/…
            $table->string('status')->default('connected');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        foreach (['articles', 'product_articles'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('shopify_article_id', 100)->nullable()->index();
                $table->text('shopify_url')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['articles', 'product_articles'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['shopify_article_id', 'shopify_url']));
        }

        Schema::dropIfExists('shopify_connections');
    }
};
