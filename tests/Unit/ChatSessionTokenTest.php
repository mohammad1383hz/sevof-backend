<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** پیام گفتگو: فرانت توکن مقاله/ایده/تحقیق می‌فرستد، دیتابیس آی‌دی عددی نگه می‌دارد، خروجی دوباره توکن است. */
class ChatSessionTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->softDeletes();
            $t->timestamps();
        });
        foreach (['articles', 'article_ideas'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('token', 26)->nullable()->unique();
                $t->unsignedBigInteger('website_id');
                $t->timestamps();
            });
        }
        Schema::create('content_research_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });
        Schema::create('content_chat_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('user_id');
            $t->string('kind')->nullable();
            $t->string('title')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
        });
        Schema::create('content_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('content_chat_session_id');
            $t->unsignedBigInteger('article_id')->nullable();
            $t->unsignedBigInteger('content_research_session_id')->nullable();
            $t->string('role');
            $t->string('kind');
            $t->text('content');
            $t->string('label')->nullable();
            $t->json('entity')->nullable();
            $t->json('options')->nullable();
            $t->boolean('answered')->default(false);
            $t->unsignedInteger('batch')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        DB::table('users')->insert([['id' => 1, 'name' => 'owner'], ['id' => 2, 'name' => 'other']]);
        DB::table('sites')->insert([['id' => 10, 'token' => 'site0000000000000000000010', 'user_id' => 1], ['id' => 20, 'token' => 'site0000000000000000000020', 'user_id' => 2]]);
        DB::table('articles')->insert([['id' => 7, 'token' => 'art00000000000000000000007', 'website_id' => 10], ['id' => 8, 'token' => 'art00000000000000000000008', 'website_id' => 20]]);
        DB::table('article_ideas')->insert(['id' => 3, 'token' => 'idea0000000000000000000003', 'website_id' => 10]);
        DB::table('content_research_sessions')->insert(['id' => 4, 'token' => 'res00000000000000000000004', 'site_id' => 10, 'user_id' => 1]);

        $this->actingAs(User::find(1), 'sanctum');
    }

    private function send(array $extra)
    {
        return $this->postJson('/api/v1/sites/site0000000000000000000010/chat-sessions/first-message', [
            'role' => 'user', 'kind' => 'article_request', 'content' => 'سلام',
        ] + $extra);
    }

    public function test_tokens_are_stored_as_ids_and_returned_as_tokens(): void
    {
        $res = $this->send([
            'article_id' => 'art00000000000000000000007',
            'content_research_session_id' => 'res00000000000000000000004',
            'metadata' => ['idea_id' => 'idea0000000000000000000003'],
        ])->assertCreated();

        $row = DB::table('content_chat_messages')->first();
        $this->assertSame(7, (int) $row->article_id);
        $this->assertSame(4, (int) $row->content_research_session_id);

        $res->assertJsonPath('message.article_id', 'art00000000000000000000007')
            ->assertJsonPath('message.research_id', 'res00000000000000000000004')
            ->assertJsonPath('message.metadata.idea_id', 'idea0000000000000000000003')
            ->assertJsonPath('message.id', $row->token)
            ->assertJsonPath('session.site_id', 'site0000000000000000000010');
    }

    public function test_tokens_of_another_site_or_numeric_ids_are_rejected(): void
    {
        $this->send(['article_id' => 'art00000000000000000000008'])->assertStatus(422);
        $this->send(['article_id' => '7'])->assertStatus(422);
        $this->send(['metadata' => ['idea_id' => 3]])->assertStatus(422);
        $this->assertSame(0, DB::table('content_chat_messages')->count());
    }

    public function test_old_messages_with_numeric_idea_id_come_back_as_token(): void
    {
        DB::table('content_chat_sessions')->insert(['id' => 1, 'token' => 'chat000000000000000000001a', 'site_id' => 10, 'user_id' => 1, 'kind' => 'chat']);
        DB::table('content_chat_messages')->insert([
            'id' => 1, 'token' => 'msg00000000000000000000001', 'content_chat_session_id' => 1, 'article_id' => 7,
            'role' => 'user', 'kind' => 'article_request', 'content' => 'x', 'metadata' => json_encode(['idea_id' => 3]),
        ]);

        $this->getJson('/api/v1/sites/site0000000000000000000010/chat-sessions/chat000000000000000000001a')
            ->assertOk()
            ->assertJsonPath('session.messages.0.metadata.idea_id', 'idea0000000000000000000003')
            ->assertJsonPath('session.messages.0.article_id', 'art00000000000000000000007');
    }
}
