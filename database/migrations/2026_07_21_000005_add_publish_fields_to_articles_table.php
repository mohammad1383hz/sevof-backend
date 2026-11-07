<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'wp_post_id')) {
                $table->unsignedBigInteger('wp_post_id')->nullable()->after('status');
            }
            if (! Schema::hasColumn('articles', 'wp_permalink')) {
                $table->string('wp_permalink')->nullable()->after('wp_post_id');
            }
            if (! Schema::hasColumn('articles', 'publish_status')) {
                // not_published | publishing | published | updating | failed | deleting | deleted
                $table->string('publish_status')->default('not_published')->after('wp_permalink');
            }
            if (! Schema::hasColumn('articles', 'publish_error')) {
                $table->text('publish_error')->nullable()->after('publish_status');
            }
            if (! Schema::hasColumn('articles', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('publish_error');
            }
            if (! Schema::hasColumn('articles', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('published_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn([
                'wp_post_id',
                'wp_permalink',
                'publish_status',
                'publish_error',
                'published_at',
                'last_synced_at',
            ]);
        });
    }
};
