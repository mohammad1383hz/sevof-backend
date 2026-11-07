<?php

namespace App\Services\Autocomplete;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use App\Support\ContentLanguage;

class JustSerpApiService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.just_serp.key');
        $this->baseUrl = rtrim((string) config('services.just_serp.base_url'), '/');
    }

    /**
     * دریافت پیشنهادات autocomplete گوگل
     * فقط کلمه کلیدی لازمه، بقیه پارامترها ثابت هستن (fa / ir)
     */
    public function googleAutocomplete(string $query, string $language = ContentLanguage::DEFAULT): array
    {
        if (trim($query) === '') {
            throw new InvalidArgumentException('The query parameter is required.');
        }

        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->timeout(20)
            ->get("{$this->baseUrl}/google/autocomplete", [
                'query'    => $query,
                'country'  => ContentLanguage::country($language),
                'language' => $language,
            ]);

        return $this->handleResponse($response, 'Autocomplete');
    }

    /**
     * جستجوی گوگل و دریافت SERP کامل
     * فقط کلمه کلیدی لازمه، بقیه پارامترها ثابت (hardcode) هستن
     */
    public function googleSearch(string $query, string $language = ContentLanguage::DEFAULT): array
    {
        if (trim($query) === '') {
            throw new InvalidArgumentException('The query parameter is required.');
        }

        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->timeout(20)
            ->get("{$this->baseUrl}/google/search", [
                'query'    => $query,
                'page'     => 0,
                'html'     => false,
                'language' => $language,
                'country'  => ContentLanguage::country($language),
                'domain'   => 'google.com',
                'safe'     => 'ACTIVE',
                'nfpr'     => 'INCLUDE_CORRECTED_RESULTS',
                'filter'   => 'ENABLED',
            ]);

        return $this->handleResponse($response, 'Google Search');
    }

    protected function handleResponse($response, string $context): array
    {
        if ($response->failed()) {
            Log::error("JustSerpApi [{$context}] Error", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to fetch data from the external service',
                'data'    => [],
            ];
        }

        return [
            'success' => true,
            'data'    => $response->json('data') ?? [],
        ];
    }
}