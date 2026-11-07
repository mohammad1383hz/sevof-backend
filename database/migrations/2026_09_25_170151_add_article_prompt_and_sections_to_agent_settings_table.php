<?php
// database/migrations/xxxx_xx_xx_add_article_prompt_and_sections_to_agent_settings_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            // ⬅️ prompt دلخواه کاربر برای تولید متن مقاله (جدا از image_user_prompt)
            $table->text('article_user_prompt')->nullable()->after('image_user_prompt');

            // ⬅️ بازه‌ی تعداد بخش/heading مقاله، مثلا 6 تا 8
            $table->unsignedTinyInteger('min_sections')->nullable()->after('article_user_prompt');
            $table->unsignedTinyInteger('max_sections')->nullable()->after('min_sections');
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn(['article_user_prompt', 'min_sections', 'max_sections']);
        });
    }
};
