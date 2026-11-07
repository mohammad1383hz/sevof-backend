<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 15)->nullable()->unique()->after('email');
            $table->string('google_id')->nullable()->unique()->after('phone');
            $table->string('avatar')->nullable()->after('google_id');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'google_id', 'avatar', 'phone_verified_at']);
        });
    }
};
