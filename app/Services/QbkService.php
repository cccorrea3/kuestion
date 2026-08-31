<?php

namespace App\Services;

use App\Contracts\RagProviderInterface;
use App\Exceptions\KuaforiaException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proveedor RAG para QuBeKa (Ola 1, Punto 1 — Fase 3).
 *
 * Contrato confirmado (corregido v1.1 — workspace_id resuelto desde token):
 *   POST {QUBKA_API_URL}/query
 *   Authorization: Bearer {credential['api_token']}
 *   Body: {"question": "texto"}
 *   Response: {"answer": "...", "confidence": 0.5, "sources": [...], "found": true}
 *
 * El workspace se resuelve desde el token del agente via middleware CheckWorkspace
 * de QuBeKa. No se envía en el body.
 */
class QbkService implements RagProviderInterface
{
    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null, ?array $credential = null): KuaforiaResponse
    {
        if (Cache::get('qbk:paused')) {
            throw new KuaforiaException('QuBeKa en pausa temporal. Intenta de nuevo en unos segundos.');
        }

        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/query';

        $response = Http::timeout(120)
            ->withToken($apiToken)
            ->post($url, [
                'question' => $question,
            ]);

        if ($response->failed()) {
            // 401 — key revocada/inválida: problema del repositorio, no del servicio.
            // No cuenta para el circuit breaker (P10).
            if ($response->status() === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            $failures = Cache::increment('qbk:failures', 1, 120);
            if ($failures >= 3) {
                Cache::put('qbk:paused', true, 60);
                Cache::forget('qbk:failures');
            }

            Log::warning('QuBeKa request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'failures' => $failures,
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$response->status(), $response->status());
        }

        Cache::forget('qbk:failures');

        $body = $response->json();

        return new KuaforiaResponse(
            answerText: $body['answer'] ?? '',
            confidence: (float) ($body['confidence'] ?? 0),
            sources: $body['sources'] ?? [],
            conversationId: null, // QuBeKa no usa conversation_id
        );
    }
}
