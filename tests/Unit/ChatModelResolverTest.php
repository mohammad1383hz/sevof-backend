<?php

namespace Tests\Unit;

use App\Services\AI\ChatModelResolver;
use PHPUnit\Framework\TestCase;

class ChatModelResolverTest extends TestCase
{
    public function test_pro_model_only_for_paid_users(): void
    {
        $this->assertSame('gpt-5.6-luna', ChatModelResolver::resolve('youtix-2-pro', true));
        $this->assertSame('gpt-4o-mini', ChatModelResolver::resolve('youtix-2-pro', false));
    }

    public function test_paid_user_gets_mini_only_when_explicitly_chosen(): void
    {
        $this->assertSame('gpt-4o-mini', ChatModelResolver::resolve('youtix-mini', true));
        $this->assertSame('gpt-5.6-luna', ChatModelResolver::resolve('something-else', true));
        $this->assertSame('gpt-5.6-luna', ChatModelResolver::resolve(null, true));
    }

    public function test_free_user_always_gets_mini(): void
    {
        $this->assertSame('gpt-4o-mini', ChatModelResolver::resolve(null, false));
        $this->assertSame('gpt-4o-mini', ChatModelResolver::resolve('gpt-5.6-luna', false));
    }

    public function test_legacy_ids_still_resolve(): void
    {
        $this->assertSame('gpt-5.6-luna', ChatModelResolver::resolve('gpt-5.4-mini', true));
        $this->assertSame('gpt-5.6-luna', ChatModelResolver::resolve('gpt-5.6-luna', true));
        $this->assertSame('gpt-4o-mini', ChatModelResolver::resolve('gpt-5-nano', true));
    }
}
