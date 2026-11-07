<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;


    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'last_login_at'  => 'datetime',
        'password'       => 'hashed',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }
}
