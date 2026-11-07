<?php
// database/migrations/2026_09_06_120000_create_banners_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            // هر مقاله دقیقاً یک بنر دارد (one-to-one)
            $table->foreignId('article_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // pending | generating | generated | failed
            $table->string('status')->default('pending');

            // خروجی نهایی: یک قطعه HTML خودکفا (استایل داخل همون div)
            $table->longText('html')->nullable();

            // اسنپ‌شات از لوگو/اسم سایت در لحظه‌ی تولید (برای اینکه اگه بعداً
            // لوگوی سایت عوض شد، بنرهای قبلی رفرنس درستی داشته باشن)
            $table->string('logo_url')->nullable();
            $table->string('site_name')->nullable();

            // پرامپتی که واقعاً به AI فرستاده شده (برای دیباگ / رجنریت دستی)
            $table->text('prompt')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
