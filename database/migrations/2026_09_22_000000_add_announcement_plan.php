<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('audience', 20)->default('all'); // all | free | paid | plans
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // فقط وقتی audience = plans باشه استفاده می‌شه
        Schema::create('announcement_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unique(['announcement_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_plan');
        Schema::dropIfExists('announcements');
    }
};
