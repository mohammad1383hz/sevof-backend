<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_research_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('status', 30)->default('queued');
            $table->text('prompt')->nullable();
            $table->string('data_source', 30)->default('demo');
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'type', 'created_at']);
        });

        Schema::create('keyword_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_research_session_id')->constrained('content_research_sessions')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('primary_keyword');
            $table->json('related_keywords');
            $table->string('search_intent', 30);
            $table->unsignedInteger('estimated_search_volume');
            $table->string('volume_label', 30);
            $table->string('trend_direction', 20);
            $table->smallInteger('trend_percentage');
            $table->string('difficulty', 20);
            $table->unsignedTinyInteger('opportunity_score');
            $table->unsignedInteger('competitor_count')->default(0);
            $table->string('data_source', 30)->default('demo');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('site_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_research_session_id')->constrained('content_research_sessions')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('domain')->nullable();
            $table->string('display_name');
            $table->unsignedInteger('best_position')->nullable();
            $table->unsignedTinyInteger('visibility_score');
            $table->string('threat_level', 20);
            $table->unsignedTinyInteger('threat_score');
            $table->string('data_source', 30)->default('demo');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('content_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_research_session_id')->constrained('content_research_sessions')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('site_competitor_id')->nullable()->constrained('site_competitors')->nullOnDelete();
            $table->string('query');
            $table->string('title');
            $table->string('gap_type', 30);
            $table->text('description');
            $table->text('recommended_angle');
            $table->unsignedTinyInteger('opportunity_score');
            $table->string('threat_level', 20);
            $table->string('data_source', 30)->default('demo');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_gaps');
        Schema::dropIfExists('site_competitors');
        Schema::dropIfExists('keyword_clusters');
        Schema::dropIfExists('content_research_sessions');
    }
};
