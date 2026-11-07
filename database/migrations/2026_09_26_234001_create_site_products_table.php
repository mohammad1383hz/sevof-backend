<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('site_product_categories')->nullOnDelete();
            $table->json('category_ids')->nullable();
            $table->string('source'); // woocommerce | crawled
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('url')->nullable();
            $table->string('sku')->nullable();
            $table->longText('description')->nullable();
            $table->text('short_description')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->json('images')->nullable();
            $table->string('stock_status')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_products');
    }
};
