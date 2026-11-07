<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Announcement extends Model
{
    public const AUDIENCES = [
        'all' => 'All users',
        'free' => 'Free users',
        'paid' => 'Paid users',
        'plans' => 'Specific plans',
        'gsc_not_connected' => 'Users without Search Console',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'announcement_plan');
    }
}
