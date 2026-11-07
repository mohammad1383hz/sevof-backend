<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();

            // Agent تولید ایده
            $table->boolean('ideas_enabled')->default(false);
            $table->unsignedInteger('daily_idea_count')->nullable(); // فعلاً فقط ذخیره می‌شود؛ سرویس تولید ایده هنوز از آن استفاده نمی‌کند (استاتیک است)
            $table->timestamp('last_ideas_run_at')->nullable();
            $table->timestamp('next_ideas_run_at')->nullable();

            // Agent تولید مقاله (کاملاً مستقل از Agent بالا)
            $table->boolean('articles_enabled')->default(false);
            $table->unsignedInteger('daily_article_limit')->nullable();
            $table->timestamp('last_articles_run_at')->nullable();
            $table->timestamp('next_articles_run_at')->nullable();

            $table->string('status')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_settings');
    }
};
