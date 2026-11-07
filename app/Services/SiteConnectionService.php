<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteConnection;
use Illuminate\Support\Str;

class SiteConnectionService
{
    public function create(Site $site): array
    {
        $credentials = [
            'client_id' => (string) Str::uuid(),
            'client_secret' => Str::random(64),
            'api_key' => Str::random(40),
        ];

        $connection = SiteConnection::updateOrCreate(
            ['site_id' => $site->id],
            [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'api_key' => $credentials['api_key'],
                'status' => SiteConnection::STATUS_PENDING,
                'wp_url' => null,
                'wp_version' => null,
                'plugin_version' => null,
                'connected_at' => null,
                'disconnected_at' => null,
                'last_error' => null,
            ],
        );

        return [
            'connection' => $connection,
            'client_secret' => $credentials['client_secret'],
        ];
    }
}
