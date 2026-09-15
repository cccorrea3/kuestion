<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ola 3, Punto 1 — B.4: registro de una carga de documento (base del flujo
 * asíncrono, recuperación ante fallo y aviso de duplicado por hash).
 *
 * Estados: procesando → listo | error | cancelado.
 */
class DocumentUpload extends Model
{
    public const ESTADO_PROCESANDO = 'procesando';

    public const ESTADO_LISTO = 'listo';

    public const ESTADO_ERROR = 'error';

    public const ESTADO_CANCELADO = 'cancelado';

    protected $fillable = [
        'user_id',
        'repository_id',
        'nombre',
        'hash',
        'estado',
        'qbk_session_id',
        'chunks_totales',
        'chunks_procesados',
        'nodos_propuestos',
        'contexto',
        'error',
        'intentos',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
