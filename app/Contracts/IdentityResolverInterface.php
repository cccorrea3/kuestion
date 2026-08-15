<?php

namespace App\Contracts;

use App\Services\ResolvedIdentity;

/**
 * Resolución de identidad de un repositorio (Sistema de Conectores RAG — Fase B).
 *
 * La implementación concreta (App\Services\IdentityResolver) resuelve 100% vía MCP
 * con la tool `get_client_context` (decisión A1); la vía REST quedó descartada.
 */
interface IdentityResolverInterface
{
    /**
     * Resuelve la identidad del tenant desde la credencial del repositorio.
     *
     * @param  array<string, mixed>  $credential  Forma de repositories.credential
     *                                            (p.ej. ['api_key' => 'kfr_...'])
     */
    public function resolveIdentity(array $credential): ResolvedIdentity;
}
