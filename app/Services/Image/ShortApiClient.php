<?php

namespace App\Services\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * فقط یک مسئولیت داره: با یک token مشخص، یک job روی ShortAPI بساز و تا
 * نتیجه صبر کن. انتخاب کلید، توزیع بار، و fallback به Kie.ai/OpenAI همگی
 * در ImageGenerationService انجام می‌شه، نه اینجا.
 */
class ShortApiClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.shortapi.base_url'), '/');
    }

    /**
     * @return string[] لیست URL تصاویر
     */
    public function generate(string $token, string $model, array $args, int $timeoutSeconds): array
    {
        $deadline = microtime(true) + max(5, $timeoutSeconds);

        $jobId = $this->createJob($token, $model, $args, $deadline);

        return $this->waitForResult($token, $jobId, $deadline);
    }

    protected function createJob(string $token, string $model, array $args, float $deadline): string
    {
        $response = Http::withToken($token)
            ->timeout($this->secondsLeft($deadline, 20))
            ->post("{$this->baseUrl}/job/create", [
                'model' => $model,
                'args' => $args,
            ]);

        $data = $response->json();

        Log::info('ShortAPI createJob', [
            'model' => $model,
            'status' => $response->status(),
        ]);

        if (! $response->successful() || (int) ($data['code'] ?? 0) !== 0) {
            $message = $data['info'] ?? $data['message'] ?? $response->body();

            throw new RuntimeException("ShortAPI [http_status:{$response->status()}]: {$message}");
        }

        $jobId = $data['job_id'] ?? $data['id'] ?? $data['data']['job_id'] ?? $data['data']['id'] ?? null;

        if (! $jobId) {
            throw new RuntimeException('ShortAPI: no job_id in the response.');
        }

        return $jobId;
    }

    protected function waitForResult(string $token, string $jobId, float $deadline): array
    {
        while (microtime(true) < $deadline) {
            $response = Http::withToken($token)
                ->timeout($this->secondsLeft($deadline, 15))
                ->get("{$this->baseUrl}/job/query", ['id' => $jobId]);

            $data = $response->json();

            if (! $response->successful() || (int) ($data['code'] ?? 0) !== 0) {
                $message = $data['info'] ?? $data['message'] ?? $response->body();

                throw new RuntimeException("ShortAPI [http_status:{$response->status()}]: {$message}");
            }

            $status = $data['status'] ?? $data['data']['status'] ?? null;
            $images = $data['images']
                ?? $data['output']['images']
                ?? $data['data']['images']
                ?? $data['data']['result']['images']
                ?? null;

            if (! empty($images)) {
                return array_values(array_filter(array_map(
                    fn ($img) => is_array($img) ? ($img['url'] ?? null) : $img,
                    $images
                )));
            }

            if (in_array($status, ['failed', 'error'], true) || (int) $status === 2) {
                throw new RuntimeException('ShortAPI: image generation failed or finished without output.');
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 1) {
                break;
            }

            sleep((int) min(3, floor($remaining)));
        }

        throw new RuntimeException('ShortAPI: timed out.');
    }

    protected function secondsLeft(float $deadline, int $cap): int
    {
        return max(1, min($cap, (int) floor($deadline - microtime(true))));
    }
}
