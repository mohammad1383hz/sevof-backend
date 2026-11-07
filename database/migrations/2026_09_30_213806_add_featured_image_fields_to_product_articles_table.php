<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_articles', function (Blueprint $table) {
            $table->string('image_status')->nullable()->index();
            $table->text('image_error')->nullable();
            $table->text('image_prompt')->nullable();
            $table->string('image_alt', 255)->nullable();
            $table->string('image_model')->nullable();
            $table->string('image_style')->nullable();
            $table->text('image_user_prompt')->nullable();
            $table->string('image_path')->nullable();
            $table->text('image_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_articles', function (Blueprint $table) {
            $table->dropIndex(['image_status']);

            $table->dropColumn([
                'image_status',
                'image_error',
                'image_prompt',
                'image_alt',
                'image_model',
                'image_style',
                'image_user_prompt',
                'image_path',
                'image_url',
            ]);
        });
    }
};
