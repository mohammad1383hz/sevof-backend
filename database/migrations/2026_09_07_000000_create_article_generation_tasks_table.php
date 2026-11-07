<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_generation_tasks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('status')->index();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_generation_tasks');
    }
};
