<?php
// database/migrations/xxxx_xx_xx_create_site_keyword_trends_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_keyword_trends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('geo')->default('IR');
            $table->string('timeframe')->default('now 7-d');
            $table->json('top_queries')->nullable();
            $table->json('rising_queries')->nullable();
            $table->string('status')->default('pending'); // pending|fetched|failed
            $table->text('error_message')->nullable();
                        $table->json('interest_over_time')->nullable();

            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_keyword_trends');
    }
};