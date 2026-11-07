<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteConnection extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            'past_posts_synced_at' => 'datetime',
            'past_posts_sync_started_at' => 'datetime',
            'past_posts_total' => 'integer',
            'past_posts_fetched' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SiteConnectionLog::class, 'site_id', 'site_id');
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
