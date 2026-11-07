<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->string('content_model')->default('gpt-5-nano')->after('default_publish_status');
            $table->boolean('image_generation_enabled')->default(true)->after('content_model');
            $table->string('image_model')->nullable()->after('image_generation_enabled');
            $table->unsignedTinyInteger('images_per_article')->default(0)->after('image_model');
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn(['content_model', 'image_generation_enabled', 'image_model', 'images_per_article']);
        });
    }
};
