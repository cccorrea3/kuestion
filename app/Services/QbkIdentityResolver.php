<?php

namespace App\Services;

use App\Contracts\IdentityResolverInterface;
use App\Exceptions\KuaforiaException;

/**
 * Resolución de identidad para QuBeKa (Ola 1, Punto 1 — Fase 4).
 *
 * Stub temporal: resolveIdentity() lanza excepción hasta que la Fase 4
 * implemente la llamada real a GET {QUBKA_API_URL}/agent/me.
 */
class QbkIdentityResolver implements IdentityResolverInterface
{
    public function resolveIdentity(array $credential): ResolvedIdentity
    {
        throw new KuaforiaException('QbkIdentityResolver: resolveIdentity no implementado aún (Fase 4 pendiente).', 501);
    }
}
