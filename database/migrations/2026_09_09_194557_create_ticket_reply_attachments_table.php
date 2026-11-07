// database/migrations/2026_09_09_000000_create_ticket_reply_attachments_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_reply_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_reply_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable(); // بایت
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reply_attachments');
    }
};
