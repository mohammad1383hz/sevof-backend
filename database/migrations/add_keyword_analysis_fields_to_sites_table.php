<?php
// database/migrations/xxxx_xx_xx_add_keyword_analysis_fields_to_sites_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('final_keyword')->nullable()->after('keywords');
            $table->string('final_title')->nullable()->after('final_keyword');
            $table->string('keyword_analysis_status')->default('not_started')->after('final_title');
            $table->text('keyword_analysis_error')->nullable()->after('keyword_analysis_status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['final_keyword', 'final_title', 'keyword_analysis_status', 'keyword_analysis_error']);
        });
    }
};