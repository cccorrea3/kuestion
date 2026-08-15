<?php

namespace App\Contracts;

use App\Services\KuaforiaResponse;

interface RagProviderInterface
{
    /**
     * Consulta al proveedor RAG y devuelve la respuesta normalizada.
     *
     * Interfaz mínima para el caso de uso de vigilancia: un único método.
     * $tenantSlug (opcional, detalle de Kuaforia) lo resuelve el llamador desde el
     * repositorio de la pregunta/usuario (Sistema de Conectores RAG — Fase D);
     * los proveedores que no lo usen pueden ignorarlo.
     */
    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null): KuaforiaResponse;
}
