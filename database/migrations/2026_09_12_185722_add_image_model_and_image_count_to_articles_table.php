<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // مدل تولید تصویر انتخابی کاربر (کلید config/image_models.php).
            // پیش‌فرض همون مدل رایگان (cloudflare/flux) هست.
            $table->string('image_model')->default('flux')->after('image_status');

            // تعداد تصویری که باید برای این مقاله تولید بشه.
            // کاربر رایگان همیشه ۱، کاربر Pro می‌تونه بیشتر انتخاب کنه.
            $table->unsignedTinyInteger('image_count')->default(1)->after('image_model');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['image_model', 'image_count']);
        });
    }
};
