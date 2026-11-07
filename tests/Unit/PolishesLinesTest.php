<?php

namespace Tests\Unit;

use App\Services\AI\Concerns\PolishesLines;
use App\Support\PersianText;
use Tests\TestCase;

class PolishesLinesTest extends TestCase
{
    private function polisher(array $reply): object
    {
        return new class($reply)
        {
            use PolishesLines;

            public ?string $sentUser = null;

            public function __construct(private array $reply) {}

            public function chat(...$args): array
            {
                $this->sentUser = $args['user'];

                return ['lines' => $this->reply];
            }

            public function run(string $content): string
            {
                return $this->polishLines($content, ['cliché'], fn ($l) => PersianText::findPhrases($l, PersianText::AI_CLICHES) !== []);
            }
        };
    }

    public function test_only_flagged_lines_are_sent_and_replaced_keeping_list_marker(): void
    {
        $content = "### تیتر امروزه\n\nپاراگراف سالم.\n\n- امروزه این روش رایج است.";
        $p = $this->polisher([['index' => 4, 'text' => 'این روش حالا رایج است.']]);

        $this->assertSame("### تیتر امروزه\n\nپاراگراف سالم.\n\n- این روش حالا رایج است.", $p->run($content));
        $this->assertStringNotContainsString('پاراگراف سالم', $p->sentUser);
        $this->assertStringNotContainsString('تیتر', $p->sentUser);
    }

    public function test_line_is_kept_when_placeholder_is_lost(): void
    {
        $content = 'امروزه [این راهنما](INTERNAL_LINK_URL) را بخوانید.';
        $p = $this->polisher([['index' => 0, 'text' => 'این راهنما را بخوانید.']]);

        $this->assertSame($content, $p->run($content));
    }
}
