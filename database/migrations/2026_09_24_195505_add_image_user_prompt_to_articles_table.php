<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
// database/migrations/xxxx_add_image_user_prompt_to_articles_table.php
public function up(): void
{
    Schema::table('articles', function (Blueprint $table) {
        $table->text('image_user_prompt')->nullable()->after('image_prompt');
    });
}

public function down(): void
{
    Schema::table('articles', function (Blueprint $table) {
        $table->dropColumn('image_user_prompt');
    });
}
};
