<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_slug',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function questions(): HasMany
    {
        // ponytail: questions.user_id stores UUID strings (from HasUuids trait),
        // users.id is auto-increment bigint — relationship won't match rows
        // until M12 seeder creates user with matching UUID as the primary key.
        // Schema-level fix (users.id → uuid) deferred until multi-user is built.
        return $this->hasMany(Question::class);
    }
}
