<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('idea_generation_mode')->nullable()->after('idea_generation_batch');
            $table->text('idea_generation_prompt')->nullable()->after('idea_generation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['idea_generation_mode', 'idea_generation_prompt']);
        });
    }
};
