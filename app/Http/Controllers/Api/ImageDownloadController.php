<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Image\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * دانلود تصاویر مقاله با فرمت WebP.
 * فایل‌های /storage روی nginx هدر CORS ندارند؛ پس مرورگر نمی‌تواند خودش تبدیل/نام‌گذاری کند.
 * فقط فایل‌های دیسک public (که به هر حال عمومی‌اند) سرو می‌شوند.
 */
class ImageDownloadController extends Controller
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function webp(Request $request, ImageOptimizer $optimizer): Response
    {
        $request->validate(['url' => ['required', 'string', 'max:2048']]);

        $path = rawurldecode((string) parse_url((string) $request->input('url'), PHP_URL_PATH));
        $relative = Str::after($path, '/storage/');
        $root = realpath(storage_path('app/public'));
        $file = $root && Str::startsWith($path, '/storage/') ? realpath($root.DIRECTORY_SEPARATOR.$relative) : false;

        if (! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)
            || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return response()->json(['message' => 'Image not found.'], 404);
        }

        $binary = (string) file_get_contents($file);
        $isWebp = (@getimagesizefromstring($binary)['mime'] ?? null) === 'image/webp';
        $webp = $isWebp ? $binary : $optimizer->toWebp($binary);

        if (! $webp) {
            return response()->json(['message' => 'Could not convert the image to WebP.'], 422);
        }

        return response($webp, 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'attachment; filename="'.pathinfo($file, PATHINFO_FILENAME).'.webp"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
