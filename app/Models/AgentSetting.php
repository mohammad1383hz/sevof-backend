<?php

namespace App\Models;

use App\Support\AutopilotScheduleText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSetting extends Model
{
    protected $guarded = ['id'];

protected $casts = [
    'agent_enabled' => 'boolean',
    'refresh_enabled' => 'boolean',
    'last_refresh_run_at' => 'datetime',
    'ideas_enabled' => 'boolean',
    'articles_enabled' => 'boolean',
    'daily_idea_count' => 'integer',
    'daily_article_limit' => 'integer',
    'publish_interval_days' => 'integer',
    'daily_start_hour' => 'integer',
    'default_publish_status' => 'string',
    'content_model' => 'string',
    'image_generation_enabled' => 'boolean',
    'image_model' => 'string',
    'images_per_article' => 'integer',
    'image_user_prompt' => 'string',   // ← جدید
    'last_ideas_run_at' => 'datetime',
    'next_ideas_run_at' => 'datetime',
    'last_articles_run_at' => 'datetime',
    'next_articles_run_at' => 'datetime',
];
    protected $appends = [
        'schedule_short_preview',
        'schedule_full_description',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getScheduleShortPreviewAttribute(): string
    {
        return AutopilotScheduleText::shortPreview(
            (int) ($this->daily_article_limit ?? 1),
            (int) ($this->publish_interval_days ?? 1),
        );
    }

    public function getScheduleFullDescriptionAttribute(): string
    {
        return AutopilotScheduleText::fullDescription(
            (int) ($this->daily_article_limit ?? 1),
            (int) ($this->publish_interval_days ?? 1),
            (int) ($this->daily_start_hour ?? 9),
            (string) ($this->default_publish_status ?? 'draft'),
        );
    }

    /**
     * تنها مرجع تصمیم‌گیری برای فعال بودن Auto Pilot.
     * articles_enabled / ideas_enabled دیگر در این تصمیم دخیل نیستند
     * (نگه داشته شده‌اند برای استفاده‌ی احتمالی آینده).
     */
    public function isActive(): bool
    {
        return (bool) $this->agent_enabled;
    }

    public function isDueForNextArticleCycle(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (! $this->last_articles_run_at) {
            return true;
        }

        return $this->last_articles_run_at
            ->copy()
            ->addDays($this->publish_interval_days ?? 1)
            ->lte(now());
    }

    public function isDueForNextIdeaCycle(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (! $this->last_ideas_run_at) {
            return true;
        }

        return $this->last_ideas_run_at
            ->copy()
            ->addDays($this->publish_interval_days ?? 1)
            ->lte(now());
    }
}
