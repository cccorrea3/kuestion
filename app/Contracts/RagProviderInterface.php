<?php

namespace App\Contracts;

use App\Services\KuaforiaResponse;

interface RagProviderInterface
{
    /**
     * Consulta al proveedor RAG y devuelve la respuesta normalizada.
     *
     * Interfaz mínima para el caso de uso de vigilancia: un único método.
     * $tenantSlug (opcional) lo resuelve el llamador desde el repositorio de la
     * pregunta/usuario (Sistema de Conectores RAG — Fase D); los proveedores que
     * no lo usen pueden ignorarlo.
     *
     * $credential (opcional) contiene la credencial del repositorio (ej:
     * ['api_key' => 'kfr_...'] para Kuaforia, ['api_token' => '...'] para QuBeKa).
     * Kuaforia lo ignora (usa config global); QuBeKa lo necesita para autenticar.
     */
    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null, ?array $credential = null): KuaforiaResponse;
}
