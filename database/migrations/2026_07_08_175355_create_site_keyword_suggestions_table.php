<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_keyword_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->json('suggestions')->nullable();       // آرایه پیشنهادات autocomplete
            $table->unsignedInteger('verbatim_relevance')->nullable();
            $table->enum('status', ['pending', 'fetched', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_keyword_suggestions');
    }
};