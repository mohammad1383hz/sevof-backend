<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ImageDownloadController;
use App\Services\Image\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImageDownloadTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'yx-img-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app/public/articles');
        File::put($this->storage.'/app/secret.txt', 'secret');
        $img = imagecreatetruecolor(8, 8);
        imagepng($img, $this->storage.'/app/public/articles/coffee-maker.png');
        app()->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function download(string $url)
    {
        return app(ImageDownloadController::class)->webp(Request::create('/api/v1/images/webp', 'GET', ['url' => $url]), app(ImageOptimizer::class));
    }

    public function test_public_image_is_returned_as_named_webp(): void
    {
        $res = $this->download('https://api.youtix.site/storage/articles/coffee-maker.png');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/webp', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="coffee-maker.webp"', $res->headers->get('Content-Disposition'));
        $this->assertSame('image/webp', getimagesizefromstring($res->getContent())['mime']);
    }

    public function test_files_outside_public_storage_are_refused(): void
    {
        $this->assertSame(404, $this->download('https://api.youtix.site/storage/../secret.txt')->getStatusCode());
        $this->assertSame(404, $this->download('https://api.youtix.site/storage/%2e%2e/secret.txt')->getStatusCode());
        $this->assertSame(404, $this->download('https://api.youtix.site/index.php')->getStatusCode());
        $this->assertSame(404, $this->download('https://api.youtix.site/storage/articles/missing.png')->getStatusCode());
    }
}
