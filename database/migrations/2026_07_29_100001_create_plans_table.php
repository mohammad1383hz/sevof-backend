<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('token_limit')->default(0);
            $table->integer('site_limit')->default(1);
            $table->integer('article_limit')->default(5);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_free')->default(false);
            $table->unsignedBigInteger('price')->default(0);
            $table->json('features')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
