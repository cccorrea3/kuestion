<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_slug',
        'uuid',
        'email_notifications',
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
            'email_notifications' => 'boolean',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'user_id', 'uuid');
    }

    /**
     * Ruta del canal mail: sin esto, el canal mail de Laravel no tiene destinatario
     * (las notificaciones mail necesitan routeNotificationFor('mail')).
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (! $user->uuid) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }
}
