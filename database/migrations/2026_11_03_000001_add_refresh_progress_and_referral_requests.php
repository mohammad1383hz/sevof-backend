<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - article_refreshes.stage: مرحله‌ی فعلی ساخت/انتشار به‌روزرسانی، برای نمایش زنده به کاربر
 * - site_connections.past_posts_*: پیشرفت خواندن مقاله‌های گذشته (چندتا از چندتا)، که در چند Job پشت سر هم انجام می‌شود
 * - referral_requests: درخواست همکاری در فروش (کسب درآمد با معرفی)؛ فعلاً فقط ثبت و بررسی دستی
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_refreshes', function (Blueprint $table) {
            $table->string('stage', 20)->nullable()->after('status');
            $table->timestamp('stage_started_at')->nullable()->after('stage');
        });

        Schema::table('site_connections', function (Blueprint $table) {
            $table->timestamp('past_posts_sync_started_at')->nullable()->after('past_posts_synced_at');
            $table->unsignedInteger('past_posts_total')->nullable()->after('past_posts_sync_started_at');
            $table->unsignedInteger('past_posts_fetched')->nullable()->after('past_posts_total');
        });

        Schema::create('referral_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 15);
            $table->json('channels');
            $table->string('audience_size', 20)->nullable();
            $table->text('description');
            $table->string('status', 20)->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_requests');

        Schema::table('site_connections', function (Blueprint $table) {
            $table->dropColumn(['past_posts_sync_started_at', 'past_posts_total', 'past_posts_fetched']);
        });

        Schema::table('article_refreshes', function (Blueprint $table) {
            $table->dropColumn(['stage', 'stage_started_at']);
        });
    }
};
