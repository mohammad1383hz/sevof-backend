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
       Schema::create('discounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = تخفیف کلی
    $table->unsignedTinyInteger('percentage');
    $table->unsignedInteger('duration_minutes')->nullable(); // null = بدون انقضا (فقط برای تخفیف کلی معنی داره)
    $table->boolean('activate_on_login')->default(false);    // فقط برای تخفیف اختصاصی کاربر
    $table->timestamp('activated_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
