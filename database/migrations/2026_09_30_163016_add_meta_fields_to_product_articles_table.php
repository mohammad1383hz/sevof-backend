<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_articles', function (Blueprint $table) {
            $table->string('meta_title')->nullable()->after('word_count');
            $table->text('meta_description')->nullable()->after('meta_title');
            $table->text('excerpt')->nullable()->after('meta_description');
            $table->string('focus_keyword')->nullable()->after('excerpt');
            $table->string('slug')->nullable()->after('focus_keyword');
            $table->string('og_title')->nullable()->after('slug');
            $table->text('og_description')->nullable()->after('og_title');
            $table->json('faq_schema')->nullable()->after('og_description');
            $table->json('product_schema')->nullable()->after('faq_schema');
            $table->timestamp('meta_generated_at')->nullable()->after('product_schema');
        });
    }

    public function down(): void
    {
        Schema::table('product_articles', function (Blueprint $table) {
            $table->dropColumn([
                'meta_title', 'meta_description', 'excerpt', 'focus_keyword', 'slug',
                'og_title', 'og_description', 'faq_schema', 'product_schema', 'meta_generated_at',
            ]);
        });
    }
};
