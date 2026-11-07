<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleIdea;
use App\Models\ArticleSection;
use App\Models\Site;
use App\Services\AI\ArticleWriterService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RewriteUserImagePromptTest extends TestCase
{
    private function article(): Article
    {
        $article = (new Article)->forceFill([
            'id' => 1,
            'title' => 'چرا موها بعد از آبرسانی زود دوباره خشک می‌شوند',
            'brief' => ['focus_keyword' => 'خشکی مو بعد از آبرسانی'],
        ]);

        return $article->setRelation('site', (new Site)->forceFill(['language' => 'fa']));
    }

    public function test_title_is_appended_when_ai_drops_it(): void
    {
        $writer = Mockery::mock(ArticleWriterService::class)->makePartial();
        $writer->shouldReceive('chat')->once()->andReturn(['image_prompt' => 'عکاسی استودیویی از موی خشک زن']);

        $out = $writer->rewriteUserImagePrompt($this->article(), 'عکاسی تبلیغاتی از انار');

        $this->assertStringStartsWith('عکاسی استودیویی از موی خشک زن', $out);
        $this->assertStringContainsString('«چرا موها بعد از آبرسانی زود دوباره خشک می‌شوند»', $out);
    }

    public function test_ai_prompt_with_title_is_kept_as_is(): void
    {
        $prompt = 'تصویر برای مقاله‌ی "چرا موها بعد از آبرسانی زود دوباره خشک می‌شوند": موی خشک زیر نور نرم.';
        $writer = Mockery::mock(ArticleWriterService::class)->makePartial();
        $writer->shouldReceive('chat')->once()->andReturn(['image_prompt' => $prompt]);

        $this->assertSame($prompt, $writer->rewriteUserImagePrompt($this->article(), 'انار'));
    }

    public function test_generate_prompts_always_go_through_ai_with_user_prompt_title_and_outline(): void
    {
        $article = $this->article()->forceFill(['slug' => 'dry-hair']);
        $article->setRelation('idea', (new ArticleIdea)->forceFill(['entity_name' => 'مو', 'entity_type' => 'topic']));
        $sections = collect([
            (new ArticleSection)->forceFill(['position' => 1, 'type' => 'introduction', 'heading' => 'دلایل خشکی مو', 'subheadings' => []]),
            (new ArticleSection)->forceFill(['position' => 2, 'type' => 'body', 'heading' => 'راهکارها', 'subheadings' => []]),
        ]);

        $sent = [];
        $writer = Mockery::mock(ArticleWriterService::class)->makePartial();
        $writer->shouldReceive('chat')->once()->andReturnUsing(function ($model, $system, $user) use (&$sent) {
            $sent = compact('system', 'user');

            return ['images' => [
                ['image_prompt' => 'عکاسی استودیویی از موی خشک', 'alt_text' => 'a', 'file_name' => 'dry-hair', 'after_section' => null],
                ['image_prompt' => 'تصویر برای مقاله‌ی «چرا موها بعد از آبرسانی زود دوباره خشک می‌شوند»', 'alt_text' => 'b', 'file_name' => 'x', 'after_section' => 1],
            ]];
        });

        // حتی با ۱ پرامپت کاربر، شاخص هم از AI ساخته می‌شود (نه عیناً متن کاربر).
        $images = $writer->generateImagePrompts($article, $sections, 2, 'realistic', 'gpt-image-2', 'عکاسی تبلیغاتی از انار');

        $this->assertStringContainsString('User Image Prompt: عکاسی تبلیغاتی از انار', $sent['user']);
        $this->assertStringContainsString('Article Title: چرا موها بعد از آبرسانی', $sent['user']);
        $this->assertStringContainsString('دلایل خشکی مو', $sent['system']);
        $this->assertCount(2, $images);
        $this->assertStringNotContainsString('انار', $images[0]['image_prompt']);
        foreach ($images as $image) {
            $this->assertStringContainsString('چرا موها بعد از آبرسانی زود دوباره خشک می‌شوند', $image['image_prompt']);
        }
    }

    public function test_ai_failure_falls_back_to_user_prompt_plus_title(): void
    {
        $writer = Mockery::mock(ArticleWriterService::class)->makePartial();
        $writer->shouldReceive('chat')->once()->andThrow(new RuntimeException('down'));

        $out = $writer->rewriteUserImagePrompt($this->article(), 'سبک مینیمال');

        $this->assertStringStartsWith('سبک مینیمال', $out);
        $this->assertStringContainsString('چرا موها بعد از آبرسانی', $out);
    }
}
