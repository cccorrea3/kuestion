<?php

namespace App\Services;

/**
 * Identidad resuelta de un repositorio (Sistema de Conectores RAG — Fase B).
 *
 * Inmutable (readonly). workspace_id es null hasta que Kuaforia lo devuelva en
 * get_client_context (P2/P3): mientras tanto Kuestion usa el fallback workspace_map.
 */
class ResolvedIdentity
{
    public function __construct(
        public readonly string $tenantSlug,
        public readonly ?string $tenantName = null,
        public readonly ?string $workspaceId = null,
        public readonly array $raw = [],
    ) {}
}
