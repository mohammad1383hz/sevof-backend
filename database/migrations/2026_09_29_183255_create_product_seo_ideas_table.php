<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_seo_ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('batch');
            $table->text('user_request')->nullable();
            $table->string('title');
            $table->string('content_style');
            $table->json('categories');   // [{id,name}]
            $table->json('product_ids');  // [id, ...]
            $table->timestamps();

            $table->index(['site_id', 'batch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_seo_ideas');
    }
};
