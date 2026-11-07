<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_request_logs', function (Blueprint $table) {
            $table->id();

            // ممکن است هنگام fail شدن signature هنوز site شناسایی نشده باشد
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('api_key')->nullable();

            $table->string('endpoint');
            $table->string('method', 10);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_request_logs');
    }
};
