<?php
// database/migrations/xxxx_xx_xx_create_subscription_exit_surveys_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_exit_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger_type'); // back_button | timeout
            $table->string('reason_key');   // price | quality | no_website | other
            $table->text('reason_text')->nullable(); // اسنپ‌شاتِ متنِ گزینه در لحظه‌ی ثبت
            $table->string('page')->default('subscription');
            $table->timestamps();

            $table->index('reason_key');
            $table->index('trigger_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_exit_surveys');
    }
};
