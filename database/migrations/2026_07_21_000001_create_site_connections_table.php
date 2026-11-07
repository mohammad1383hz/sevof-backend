<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // اعتبارسنجی
            $table->string('client_id')->unique();
            $table->text('client_secret'); // به‌صورت رمزنگاری‌شده (encrypted cast) ذخیره می‌شود
            $table->string('api_key')->unique();

            // وضعیت اتصال: pending | connected | disconnected | failed
            $table->string('status')->default('pending');

            // اطلاعات سایت وردپرسی که هنگام handshake از پلاگین دریافت می‌شود
            $table->string('wp_url')->nullable();
            $table->string('wp_version')->nullable();
            $table->string('plugin_version')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique('site_id'); // هر سایت فقط یک اتصال وردپرس دارد
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_connections');
    }
};
