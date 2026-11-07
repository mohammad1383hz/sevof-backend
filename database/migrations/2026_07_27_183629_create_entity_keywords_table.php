<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_keywords', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                ->constrained('sites')
                ->cascadeOnDelete();

            $table->enum('entity_scope', ['primary', 'core']);
            $table->string('entity_name');
            $table->string('base_keyword');
            $table->unsignedInteger('total_relevance')->default(0);
            $table->json('source_variants')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'entity_scope', 'entity_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_keywords');
    }
};
