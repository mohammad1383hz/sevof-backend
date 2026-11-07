<?php

namespace App\Services\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * لایه‌ی دوم fallback. فقط با مدل nano-banana-2-lite کار می‌کنه.
 * مثل ShortApiClient، هیچ منطق انتخاب کلید/fallback‌ای اینجا نیست؛
 * فقط task می‌سازه و تا نتیجه صبر می‌کنه.
 */
class KieAiClient
{
    protected const BASE_URL = 'https://api.kie.ai';

    public const MODEL = 'nano-banana-2-lite';

    // مارکری که در پیام خطا می‌آید و ImageGenerationService با آن تشخیص می‌دهد اعتبار کلید تمام شده.
    public const CREDITS_EXHAUSTED_MARKER = 'code:402';

    protected const POLL_INTERVAL = 3;

    public function generate(string $apiKey, string $prompt, int $timeoutSeconds): string
    {
        $deadline = microtime(true) + max(5, $timeoutSeconds);

        $taskId = $this->createTask($apiKey, $prompt, $deadline);

        return $this->waitForResult($apiKey, $taskId, $deadline);
    }

    protected function createTask(string $apiKey, string $prompt, float $deadline): string
    {
        $response = Http::withToken($apiKey)
            ->timeout($this->secondsLeft($deadline, 20))
            ->post(self::BASE_URL.'/api/v1/jobs/createTask', [
                'model' => self::MODEL,
                'input' => [
                    'prompt' => $prompt,
                    'aspect_ratio' => '16:9',
                    'resolution' => '1K',
                ],
            ]);

        $data = $response->json() ?? [];

        // اعتبار کلید تمام شده (Kie با HTTP 200 و code:402 در بدنه جواب می‌دهد)
        if ((int) ($data['code'] ?? 0) === 402 || $response->status() === 402) {
            Log::warning('KieAi: اعتبار کلید تمام شد', [
                'message' => $data['msg'] ?? null,
            ]);

            throw new RuntimeException('KieAi ['.self::CREDITS_EXHAUSTED_MARKER.']: key credit exhausted.');
        }

        if ($response->failed() || (int) ($data['code'] ?? 0) !== 200) {
            $message = $data['msg'] ?? $response->body();

            Log::error('KieAi: createTask ناموفق بود', [
                'http_status' => $response->status(),
                'code' => $data['code'] ?? null,
                'message' => $message,
            ]);

            throw new RuntimeException("KieAi [http_status:{$response->status()}] [code:".($data['code'] ?? '?')."]: {$message}");
        }

        $taskId = $data['data']['taskId'] ?? null;

        if (! $taskId) {
            Log::error('KieAi: taskId در پاسخ برنگشت', [
                'response' => $data,
            ]);

            throw new RuntimeException('KieAi: no taskId in the response.');
        }

        Log::info('KieAi: task ساخته شد', [
            'task_id' => $taskId,
            'model' => self::MODEL,
        ]);

        return $taskId;
    }

    protected function waitForResult(string $apiKey, string $taskId, float $deadline): string
    {
        while (microtime(true) < $deadline) {
            $response = Http::withToken($apiKey)
                ->timeout($this->secondsLeft($deadline, 15))
                ->get(self::BASE_URL.'/api/v1/jobs/recordInfo', ['taskId' => $taskId]);

            if ($response->failed()) {
                Log::error('KieAi: استعلام وضعیت ناموفق بود', [
                    'task_id' => $taskId,
                    'http_status' => $response->status(),
                ]);

                throw new RuntimeException("KieAi [http_status:{$response->status()}]: status check failed.");
            }

            $state = $response->json('data.state');

            if ($state === 'success') {
                $result = json_decode((string) $response->json('data.resultJson'), true);
                $url = $result['resultUrls'][0] ?? null;

                if (! $url) {
                    Log::error('KieAi: تصویری در نتیجه نبود', [
                        'task_id' => $taskId,
                        'result' => $result,
                    ]);

                    throw new RuntimeException('KieAi: no image in the result.');
                }

                Log::info('KieAi: تصویر با موفقیت تولید شد', [
                    'task_id' => $taskId,
                ]);

                return $url;
            }

            if ($state === 'fail') {
                $failMsg = $response->json('data.failMsg');

                Log::warning('KieAi: تسک fail شد', [
                    'task_id' => $taskId,
                    'fail_msg' => $failMsg,
                ]);

                throw new RuntimeException('KieAi: task failed. '.$failMsg);
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 1) {
                break;
            }

            sleep((int) min(self::POLL_INTERVAL, floor($remaining)));
        }

        Log::error('KieAi: زمان انتظار تمام شد', [
            'task_id' => $taskId,
        ]);

        throw new RuntimeException('KieAi: timed out.');
    }

    protected function secondsLeft(float $deadline, int $cap): int
    {
        return max(1, min($cap, (int) floor($deadline - microtime(true))));
    }
}
