<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serp_fallback_keys', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index(); // مثلاً 'serper'
            $table->string('api_key');
            $table->string('base_url')->nullable(); // آدرس این کلید؛ اگر خالی باشد از DEFAULT_BASE_URL کلاینت استفاده می‌شود
            $table->string('status')->default('active')->index(); // active | deactive
            $table->timestamps();

            $table->unique(['provider', 'api_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serp_fallback_keys');
    }
};
