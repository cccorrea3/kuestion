<?php

namespace Tests\Fakes;

use App\Contracts\RagProviderInterface;
use App\Services\KuaforiaResponse;

/**
 * Test double del proveedor RAG (7.5): respuestas configurables, sin red.
 *
 * Reemplaza Http::fake() en feature tests que tocan Kuaforia: más rápidos,
 * sin acoplamiento al contrato HTTP. KuaforiaServiceTest se mantiene para
 * el cliente real.
 */
class FakeRagProvider implements RagProviderInterface
{
    /** Registro de llamadas: ['question' => string, 'conversation_id' => ?string, 'tenant_slug' => ?string]. */
    public array $calls = [];

    private ?KuaforiaResponse $response = null;

    private ?\Throwable $exception = null;

    public function respondWith(KuaforiaResponse $response): static
    {
        $this->response = $response;

        return $this;
    }

    public function throwWhenCalled(\Throwable $exception): static
    {
        $this->exception = $exception;

        return $this;
    }

    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null): KuaforiaResponse
    {
        $this->calls[] = [
            'question' => $question,
            'conversation_id' => $conversationId,
            'tenant_slug' => $tenantSlug,
        ];

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->response ?? new KuaforiaResponse(
            answerText: 'Respuesta del proveedor fake',
            confidence: 1.0,
            sources: [],
            conversationId: $conversationId,
        );
    }
}
