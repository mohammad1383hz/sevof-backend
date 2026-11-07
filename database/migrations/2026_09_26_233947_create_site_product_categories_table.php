<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('site_product_categories')->nullOnDelete();
            $table->string('source'); // woocommerce | crawled
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('url')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_product_categories');
    }
};
