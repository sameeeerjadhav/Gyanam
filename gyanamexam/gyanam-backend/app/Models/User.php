<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username', 'name', 'email', 'password', 'role', 'centre_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isATC(): bool      { return $this->role === 'atc'; }
    public function isDLC(): bool      { return $this->role === 'dlc'; }
    public function isScoped(): bool   { return !is_null($this->centre_id); }

    public function questionBanks()
    {
        return $this->hasMany(QuestionBank::class, 'created_by_user_id');
    }
}
