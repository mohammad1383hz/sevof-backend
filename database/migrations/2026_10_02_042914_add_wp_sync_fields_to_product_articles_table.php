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
      Schema::table('product_articles', function (Blueprint $table) {
    $table->unsignedBigInteger('wp_post_id')->nullable();
    $table->string('wp_permalink', 2048)->nullable();
    $table->string('wp_sync_status')->default('not_synced');
    $table->string('publish_status')->nullable();
    $table->text('publish_error')->nullable();
    $table->timestamp('published_at')->nullable();
    $table->timestamp('last_synced_at')->nullable();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_articles', function (Blueprint $table) {
            //
        });
    }
};
