<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de envíos de correo (Ola 2, Punto 5 — Fase A.2, spec §5).
 *
 * Índice único (user_id, event_type, reference_key, sent_at): el dedupe por
 * ventana redondea sent_at al bucket de la ventana (EmailDispatcher), así el
 * índice hace cumplir "un envío por evento/entidad por ventana" a nivel BD.
 */
class EmailLog extends Model
{
    protected $fillable = [
        'user_id',
        'event_type',
        'reference_key',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
