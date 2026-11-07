<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * به‌روزرسانی مقاله با داده‌ی سرچ کنسول: هر بار یک نسخه‌ی پیشنهادی ساخته می‌شود،
 * کاربر (یا اتوماسیون) تأییدش می‌کند و نسخه‌ی قبلی برای برگرداندن نگه داشته می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_refreshes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('trigger', 10)->default('manual');
            $table->string('reason', 30);
            $table->json('metrics')->nullable();
            $table->json('queries')->nullable();
            $table->text('summary')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->decimal('credit_used', 12, 4)->default(0);
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['article_id', 'created_at']);
        });

        Schema::table('agent_settings', function (Blueprint $table) {
            $table->boolean('refresh_enabled')->default(false)->after('agent_enabled');
            $table->timestamp('last_refresh_run_at')->nullable()->after('refresh_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn(['refresh_enabled', 'last_refresh_run_at']);
        });

        Schema::dropIfExists('article_refreshes');
    }
};
