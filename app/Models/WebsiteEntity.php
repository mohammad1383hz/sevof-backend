<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteEntity extends Model
{
   protected $guarded = [
        'id',
    ];

    protected $casts = [
        'primary_entity' => 'array',
        'core_entities' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'website_id');
    }

    /**
     * همه‌ی لینک‌های داخلی پیشنهادی (primary + core) رو یکجا برمی‌گردونه،
     * مرتب‌شده بر اساس priority — برای استفاده در Internal Linking.
     */
    public function allSuggestedLinks(): array
    {
        $links = [];

        if (! empty($this->primary_entity['links'])) {
            foreach ($this->primary_entity['links'] as $link) {
                $links[] = $link + ['entity_name' => $this->primary_entity['name'] ?? null, 'is_primary' => true];
            }
        }

        foreach ($this->core_entities ?? [] as $entity) {
            foreach ($entity['links'] ?? [] as $link) {
                $links[] = $link + ['entity_name' => $entity['name'] ?? null, 'is_primary' => false];
            }
        }

        usort($links, fn ($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return $links;
    }
}
