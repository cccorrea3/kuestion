<?php

namespace App\Services;

use App\Contracts\RagProviderInterface;
use App\Exceptions\KuaforiaException;

/**
 * Proveedor RAG para QuBeKa (Ola 1, Punto 1 — Fase 3).
 *
 * Stub temporal: consult() lanza excepción hasta que la Fase 3
 * implemente la llamada real a POST {QUBKA_API_URL}/query.
 */
class QbkService implements RagProviderInterface
{
    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null): KuaforiaResponse
    {
        throw new KuaforiaException('QbkService: consult no implementado aún (Fase 3 pendiente).', 501);
    }
}
