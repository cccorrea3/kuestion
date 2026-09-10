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

    /**
     * Ola 2, Punto 5 — Fase A.1: preferencia de correo en 3 niveles (spec §5).
     * D7: se conserva el nombre de columna `email_notifications` cambiando el tipo.
     */
    public const EMAIL_PREF_ALL = 'all';

    public const EMAIL_PREF_CRITICAL_ONLY = 'critical_only';

    public const EMAIL_PREF_NONE = 'none';

    /**
     * A.3 — niveles críticos (copy de Settings): solo revisión pendiente,
     * vigencia crítica y reconfirmación. `new_version` NO es crítico.
     */
    public const CRITICAL_EMAIL_EVENTS = [
        'pending_review',
        'contribution_approved',
        'contribution_rejected',
        'reconfirmation_due',
        'validity_alert',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'uuid',
        'email_notifications',
        'has_seen_example',
        'team_dashboard_access',
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
            'email_notifications' => 'string',
            'has_seen_example' => 'boolean',
            'team_dashboard_access' => 'string',
        ];
    }

    /**
     * A.3 — regla central "¿este evento le llega a este usuario?".
     * all: todo; critical_only: solo eventos críticos; none: nada.
     */
    public function emailPreferenceAllows(string $eventType): bool
    {
        return match ($this->email_notifications) {
            self::EMAIL_PREF_ALL => true,
            self::EMAIL_PREF_CRITICAL_ONLY => in_array($eventType, self::CRITICAL_EMAIL_EVENTS, true),
            default => false,
        };
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'user_id', 'uuid');
    }

    /**
     * Repositorios conectados (Sistema de Conectores RAG).
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class, 'user_id', 'uuid');
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
