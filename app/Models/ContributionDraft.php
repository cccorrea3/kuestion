<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Borrador de aporte de conocimiento (Ola 1, Punto 3 — Fase 4).
 *
 * Persiste el texto del aporte cuando el servicio de QuBeKa falla,
 * permitiendo reintentar sin perder la información.
 */
class ContributionDraft extends Model
{
    protected $fillable = [
        'user_id',
        'repository_id',
        'qbk_session_id',
        'texto',
        'pregunta_previa',
        'status',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
        ];
    }

    public const STATUS_PENDING = 'pending_retry';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVIEWED = 'reviewed';

    // --- Relationships ---

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    // --- Scopes ---

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Aportes enviados exitosamente que tienen una sesión de QBK pendiente de revisión.
     * (Ola 1, Punto 4 — Fase 3)
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT)
            ->whereNotNull('qbk_session_id');
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeStale(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '<', now()->subDays($days))
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED]);
    }

    // --- Helpers ---

    public function markSent(): void
    {
        $this->update(['status' => self::STATUS_SENT]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'attempts' => $this->attempts + 1,
            'last_error' => $error,
        ]);
    }
}
