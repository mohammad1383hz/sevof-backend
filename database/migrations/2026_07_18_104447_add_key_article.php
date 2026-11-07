<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       // database/migrations/xxxx_xx_xx_add_image_fields_to_articles_table.php

Schema::table('articles', function (Blueprint $table) {
    $table->text('image_prompt')->nullable()->after('content');
    $table->string('image_alt')->nullable()->after('image_prompt');
    $table->string('image_path')->nullable()->after('image_alt');
    $table->string('image_url')->nullable()->after('image_path');
    $table->enum('image_status', ['pending', 'generating', 'generated', 'failed'])
        ->default('pending')->after('image_url');
    $table->text('image_error')->nullable()->after('image_status');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
