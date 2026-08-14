<?php

namespace App\Contracts;

use App\Services\KuaforiaResponse;

interface RagProviderInterface
{
    /**
     * Consulta al proveedor RAG y devuelve la respuesta normalizada.
     *
     * Interfaz mínima para el caso de uso de vigilancia: un único método.
     * El tenant (detalle de Kuaforia) queda fuera de la interfaz; quien
     * lo necesite lo pasa por el proveedor concreto (KuaforiaService).
     */
    public function consult(string $question, ?string $conversationId = null): KuaforiaResponse;
}
