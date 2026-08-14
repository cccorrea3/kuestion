<?php

namespace App\Services;

use App\Contracts\RagProviderInterface;
use App\Exceptions\KuaforiaException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KuaforiaService implements RagProviderInterface
{
    /**
     * Resuelve el tenant desde una API key scoped de Kuaforia (prefijo kfr_).
     *
     * 6.1 — Punto único de resolución detrás de config `services.kuaforia.tenant_resolution`
     * (`rest | mcp`). La vía REST usa el endpoint liviano de validación de Kuaforia
     * (pendiente de confirmación del contrato); se deja la vía MCP documentada en el código.
     *
     * @return array{tenant_slug: string, workspace_id: ?string}
     */
    public function resolveTenantFromApiKey(string $apiKey): array
    {
        $baseUrl = rtrim(config('services.kuaforia.base_url'), '/');
        $resolution = config('services.kuaforia.tenant_resolution', 'rest');

        if ($resolution === 'mcp') {
            // Vía MCP (stateless): misma validación, contrato del puente MCP de Kuaforia.
            $url = "{$baseUrl}/api/v1/mcp";
        } else {
            // Vía REST: endpoint liviano que valida la key y devuelve el tenant.
            $url = "{$baseUrl}/api/validate-api-key";
        }

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post($url, ['stateless' => true]);

        if ($response->failed()) {
            Log::warning('Kuaforia: validación de API key falló', [
                'status' => $response->status(),
                'body' => $response->body(),
                'via' => $resolution,
            ]);

            throw new KuaforiaException(
                $response->status() === 401
                    ? 'La API key de Kuaforia es inválida o fue revocada.'
                    : 'No se pudo conectar con Kuaforia. Intenta de nuevo en unos minutos.'
            );
        }

        $body = $response->json() ?? [];
        $tenantSlug = $body['tenant_slug'] ?? $body['tenant'] ?? null;

        if (! $tenantSlug) {
            Log::warning('Kuaforia: respuesta de validación sin tenant', [
                'body' => $body,
                'via' => $resolution,
            ]);

            throw new KuaforiaException('No se pudo resolver la organización para esta API key.');
        }

        return [
            'tenant_slug' => (string) $tenantSlug,
            // El workspace_id por defecto (probablemente el único) llega si Kuaforia lo
            // expone; si no, null y Kuestion usa el mapeo tenant_slug (Fase 2, Bloque 8).
            'workspace_id' => isset($body['workspace_id']) ? (string) $body['workspace_id'] : null,
        ];
    }

    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null): KuaforiaResponse
    {
        if (Cache::get('kuaforia:paused')) {
            throw new KuaforiaException('Kuaforia en pausa temporal. Intenta de nuevo en unos segundos.');
        }

        $tenantSlug ??= auth()->user()?->tenant_slug;

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
            throw new KuaforiaException('Kuaforia respondió con error: '.$response->status());
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
