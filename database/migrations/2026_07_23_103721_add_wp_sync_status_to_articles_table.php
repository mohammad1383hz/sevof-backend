<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'wp_sync_status')) {
                // not_synced | syncing | synced | failed
                $table->string('wp_sync_status')
                    ->default('not_synced')
                    ->after('publish_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (Schema::hasColumn('articles', 'wp_sync_status')) {
                $table->dropColumn('wp_sync_status');
            }
        });
    }
};
