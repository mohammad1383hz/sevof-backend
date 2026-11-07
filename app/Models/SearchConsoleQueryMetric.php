<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchConsoleQueryMetric extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'ctr' => 'float',
            'position' => 'float',
            'fetched_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(SiteSearchConsoleProperty::class, 'site_search_console_property_id');
    }
}
