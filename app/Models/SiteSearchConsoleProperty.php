<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteSearchConsoleProperty extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'totals' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleSearchConsoleConnection::class, 'google_search_console_connection_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(SearchConsoleQueryMetric::class);
    }
}
