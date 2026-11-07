<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_console_query_metrics', function (Blueprint $table) {
            $table->unsignedInteger('previous_clicks')->nullable()->after('clicks');
            $table->unsignedInteger('previous_impressions')->nullable()->after('impressions');
            $table->decimal('previous_ctr', 8, 6)->nullable()->after('ctr');
            $table->decimal('previous_position', 10, 4)->nullable()->after('position');
            $table->smallInteger('trend_percentage')->nullable()->after('previous_position');
        });
    }

    public function down(): void
    {
        Schema::table('search_console_query_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'previous_clicks',
                'previous_impressions',
                'previous_ctr',
                'previous_position',
                'trend_percentage',
            ]);
        });
    }
};
