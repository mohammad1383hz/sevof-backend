<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            // 'password' => 'hashed',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latest();
    }

    public function tokenUsages(): HasMany
    {
        return $this->hasMany(TokenUsage::class);
    }


    public function loginLogs(): HasMany
    {
        return $this->hasMany(UserLoginLog::class);
    }

    public function contentChatSessions(): HasMany
    {
        return $this->hasMany(ContentChatSession::class);
    }

    public function apiAgents(): HasMany
    {
        return $this->hasMany(ApiAgent::class);
    }
}
