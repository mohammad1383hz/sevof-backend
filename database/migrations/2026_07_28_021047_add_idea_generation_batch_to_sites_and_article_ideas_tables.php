<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedTinyInteger('idea_generation_batch')->default(0)->after('idea_generation_error');
        });

        Schema::table('article_ideas', function (Blueprint $table) {
            $table->unsignedTinyInteger('batch')->default(1)->after('website_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('idea_generation_batch');
        });
        Schema::table('article_ideas', function (Blueprint $table) {
            $table->dropColumn('batch');
        });
    }
};
