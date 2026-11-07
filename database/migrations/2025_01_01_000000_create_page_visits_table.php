<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // اسم صفحه‌ای که لاگ می‌شه (فعلاً فقط subscription، ولی قابل توسعه‌ست)
            $table->string('page')->default('subscription')->index();

            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();

            // مدت زمان حضور به ثانیه — سمت سرور محاسبه می‌شه (left_at - entered_at)
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
