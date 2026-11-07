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
        Schema::table('articles', function (Blueprint $table) {
            $table->string('meta_title')->nullable()->after('title');
            $table->string('excerpt')->nullable()->after('meta_description');
            $table->string('focus_keyword')->nullable()->after('excerpt');
            $table->string('slug')->nullable()->after('focus_keyword');
            $table->string('og_title')->nullable()->after('slug');
            $table->text('og_description')->nullable()->after('og_title');
            $table->json('faq_schema')->nullable()->after('og_description');

            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropUnique(['slug']);

            $table->dropColumn([
                'meta_title',
                'excerpt',
                'focus_keyword',
                'slug',
                'og_title',
                'og_description',
                'faq_schema',
            ]);
        });
    }
};
