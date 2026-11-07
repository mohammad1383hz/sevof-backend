<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
       Schema::table('article_ideas', function (Blueprint $table) {
    $table->json('category')->nullable()->after('entity_type');
    $table->json('tags')->nullable()->after('category');
});
    }

    public function down(): void
    {
        // Schema::dropIfExists('article_idea_related_links');
    }
};
