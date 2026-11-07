<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageVisit extends Model
{
    use HasPublicToken;
    use HasFactory;

      protected $guarded = [];


    protected $casts = [
        'entered_at' => 'datetime',
        'left_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * بستن ویزیت‌های بازِ کاربر که رهاشون کرده (مثلاً تب رو بسته و درخواست end نرسیده).
     * مدت زمان‌شون رو بر اساس آخرین updated_at (heartbeat) می‌بندیم.
     */
    public function scopeStillOpen($query)
    {
        return $query->whereNull('left_at');
    }
}
