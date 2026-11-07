<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_chat_sessions', function (Blueprint $table) {
            $table->char('token', 26)->nullable()->unique()->after('id');
            $table->string('kind', 20)->default('chat')->after('user_id');
            $table->index(['site_id', 'user_id', 'kind']);
        });

        $this->backfillTokens();
        $this->mergeLegacySessions();
    }

    public function down(): void
    {
        Schema::table('content_chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'user_id', 'kind']);
            $table->dropUnique(['token']);
            $table->dropColumn(['token', 'kind']);
        });
    }

    private function backfillTokens(): void
    {
        DB::table('content_chat_sessions')
            ->whereNull('token')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $session): void {
                DB::table('content_chat_sessions')
                    ->where('id', $session->id)
                    ->update(['token' => (string) Str::ulid()]);
            });
    }

    private function mergeLegacySessions(): void
    {
        DB::table('sites')
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $site): void {
                DB::transaction(function () use ($site): void {
                    $sessions = DB::table('content_chat_sessions')
                        ->where('site_id', $site->id)
                        ->where('user_id', $site->user_id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get();

                    $base = $sessions->firstWhere('kind', 'analysis') ?: $sessions->first();

                    if (!$base) {
                        DB::table('content_chat_sessions')->insert([
                            'site_id' => $site->id,
                            'user_id' => $site->user_id,
                            'kind' => 'analysis',
                            'token' => (string) Str::ulid(),
                            'title' => 'تحلیل سایت',
                            'last_message_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        return;
                    }

                    DB::table('content_chat_sessions')
                        ->where('id', $base->id)
                        ->update([
                            'kind' => 'analysis',
                            'title' => 'تحلیل سایت',
                            'updated_at' => now(),
                        ]);

                    $otherIds = $sessions
                        ->pluck('id')
                        ->reject(fn ($id): bool => (int) $id === (int) $base->id)
                        ->values();

                    if ($otherIds->isNotEmpty()) {
                        DB::table('content_chat_messages')
                            ->whereIn('content_chat_session_id', $otherIds->all())
                            ->update(['content_chat_session_id' => $base->id]);

                        DB::table('content_chat_sessions')
                            ->whereIn('id', $otherIds->all())
                            ->delete();
                    }

                    $lastMessageAt = DB::table('content_chat_messages')
                        ->where('content_chat_session_id', $base->id)
                        ->max('created_at');

                    DB::table('content_chat_sessions')
                        ->where('id', $base->id)
                        ->update([
                            'last_message_at' => $lastMessageAt,
                            'updated_at' => now(),
                        ]);
                });
            });
    }
};
