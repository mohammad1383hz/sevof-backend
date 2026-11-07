<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_connection_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // connected | disconnected | reconnected | health_check | test_connection | failed
            $table->string('event');
            $table->text('message')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['site_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_connection_logs');
    }
};
