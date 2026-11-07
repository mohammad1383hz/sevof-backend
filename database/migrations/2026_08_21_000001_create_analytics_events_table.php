<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 128)->nullable()->unique();
            $table->string('event_name', 80);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('anonymous_id', 128)->nullable();
            $table->string('session_key', 128)->nullable();
            $table->string('source', 40)->default('api'); // api | frontend | system | derived
            $table->string('feature', 80)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['event_name', 'occurred_at']);
            $table->index(['session_key', 'occurred_at']);
            $table->index(['anonymous_id', 'occurred_at']);
        });

        Schema::create('analytics_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_key', 128)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('anonymous_id', 128)->nullable()->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('action_count')->default(0);
            $table->unsignedInteger('meaningful_action_count')->default(0);
            $table->unsignedInteger('page_view_count')->default(0);
            $table->unsignedInteger('api_interaction_count')->default(0);
            $table->string('funnel_stage', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sessions');
        Schema::dropIfExists('analytics_events');
    }
};
