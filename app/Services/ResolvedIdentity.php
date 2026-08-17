<?php

namespace App\Services;

/**
 * Identidad resuelta de un repositorio (Sistema de Conectores RAG — Fase B).
 *
 * Inmutable (readonly). workspace_id sale de `data.default_workspace.id` de
 * get_client_context (G7): null solo cuando el contrato no lo trae (versión
 * anterior de Kuaforia o tenant sin workspace).
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
