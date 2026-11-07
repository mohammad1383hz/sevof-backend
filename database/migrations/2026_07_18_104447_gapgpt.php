<?php
// database/migrations/2026_08_04_000000_create_gapgpt_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gapgpt', function (Blueprint $table) {
            $table->id();
            $table->string('api_key');
            $table->string('status')->default('active'); // active | deactive
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gapgpt');
    }
};
