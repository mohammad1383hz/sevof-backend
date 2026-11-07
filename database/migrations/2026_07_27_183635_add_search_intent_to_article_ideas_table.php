<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_ideas', function (Blueprint $table) {
            $table->enum('search_intent', ['informational', 'commercial', 'transactional', 'navigational'])
                ->nullable()
                ->after('entity_type');
        });
    }

    public function down(): void
    {
        Schema::table('article_ideas', function (Blueprint $table) {
            $table->dropColumn('search_intent');
        });
    }
};
