<?php

namespace App\Services;

use App\Contracts\IdentityResolverInterface;
use App\Contracts\RagProviderInterface;
use App\Exceptions\KuaforiaException;
use App\Exceptions\KuaforiaMcpException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KuaforiaService implements RagProviderInterface
{
    /**
     * Resuelve el tenant desde una API key scoped de Kuaforia (prefijo kfr_).
     *
     * Wrapper de compatibilidad (Sistema de Conectores RAG — Fase B, decisiones A3/A4):
     * delega en IdentityResolverInterface (App\Services\IdentityResolver, 100% vía MCP con
     * get_client_context) y mantiene la firma/forma de retorno para no romper los llamadores
     * actuales (Register, Settings) hasta la Fase C. La vía REST quedó descartada (A1).
     *
     * @return array{tenant_slug: string, workspace_id: ?string}
     */
    public function resolveTenantFromApiKey(string $apiKey): array
    {
        try {
            $identity = app(IdentityResolverInterface::class)->resolveIdentity(['api_key' => $apiKey]);
        } catch (KuaforiaMcpException $e) {
            // Register/Settings capturan KuaforiaException para mostrar el error en la UI.
            throw new KuaforiaException($e->getMessage(), $e->getCode(), $e);
        }

        return [
            'tenant_slug' => $identity->tenantSlug,
            'workspace_id' => $identity->workspaceId,
        ];
    }

    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null, ?array $credential = null): KuaforiaResponse
    {
        if (Cache::get('kuaforia:paused')) {
            throw new KuaforiaException('Kuaforia en pausa temporal. Intenta de nuevo en unos segundos.');
        }

        // D1 — el tenant llega explícito desde el repositorio de la pregunta/usuario
        // (Sistema de Conectores RAG); ya no se cae al tenant del usuario autenticado.
        if (! $tenantSlug) {
            throw new KuaforiaException('No se pudo resolver el tenant para la consulta.');
        }

        $baseUrl = rtrim(config('services.kuaforia.base_url'), '/');
        $url = "{$baseUrl}/api/consult/{$tenantSlug}";

        $response = Http::timeout(120)
            ->withToken(config('services.kuaforia.api_key'))
            ->post($url, [
                'question' => $question,
                'conversation_id' => $conversationId,
            ]);

        if ($response->failed()) {
            // P10 — el 401 (key revocada/inválida) es un problema del repositorio, no del
            // servicio: no cuenta para el circuit breaker (que es por servicio, no por repo).
            if ($response->status() === 401) {
                throw new KuaforiaException('La API key de Kuaforia es inválida o fue revocada.', 401);
            }

            $failures = Cache::increment('kuaforia:failures', 1, 120);
            if ($failures >= 3) {
                Cache::put('kuaforia:paused', true, 60);
                Cache::forget('kuaforia:failures');
            }
            Log::warning('Kuaforia request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'failures' => $failures,
                'tenant' => $tenantSlug,
            ]);
            // D4 — la excepción transporta el status HTTP real para que el job distinga
            // 401 (repo invalid) de otros códigos (reintento con backoff existente).
            throw new KuaforiaException('Kuaforia respondió con error: '.$response->status(), $response->status());
        }

        Cache::forget('kuaforia:failures');

        $body = $response->json();

        return new KuaforiaResponse(
            answerText: $body['answer'] ?? $body['response'] ?? '',
            confidence: (float) ($body['confidence'] ?? 0),
            sources: $body['sources'] ?? [],
            conversationId: $body['conversation_id'] ?? null,
        );
    }
}
