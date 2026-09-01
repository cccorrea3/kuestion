<?php

namespace App\Services;

use App\Exceptions\KuaforiaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de aportes de conocimiento para QuBeKa (Ola 1, Punto 3 — Fase 2).
 *
 * Contrato confirmado:
 *   POST {QUBKA_API_URL}/contribute
 *   Authorization: Bearer {credential['api_token']}
 *   Body: {"texto": "...", "origen": "kuestion", "pregunta_previa": "..."} (opcional)
 *   Response: {"session_id": 42, "status": "pendiente_revision", "resumen": "..."}
 *
 * Síncrono (~2-5s). Workspace resuelto desde el token (no se envía en body).
 */
class QbkContributionService
{
    /**
     * Enviar un aporte de conocimiento al servicio de clasificación de QuBeKa.
     *
     * @param  string  $texto  Texto del aporte (10-2000 chars)
     * @param  string|null  $preguntaPrevia  Pregunta que originó el aporte (opcional)
     * @param  array  $credential  Credenciales del repositorio ['api_token' => '...']
     * @return array{session_id: int, status: string, resumen: string}
     *
     * @throws KuaforiaException En caso de error de autenticación, permiso, o servicio.
     */
    public function contribute(
        string $texto,
        ?string $preguntaPrevia = null,
        ?array $credential = null,
    ): array {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/contribute';

        $payload = [
            'texto' => $texto,
            'origen' => 'kuestion',
        ];

        if ($preguntaPrevia !== null && $preguntaPrevia !== '') {
            $payload['pregunta_previa'] = $preguntaPrevia;
        }

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('QbK contribute timeout', ['error' => $e->getMessage()]);

            throw new KuaforiaException('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.', 504, $e);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($status === 403) {
                throw new KuaforiaException('No tenés permiso de escritura en este workspace de QuBeKa.', 403);
            }

            Log::warning('QbK contribute failed', [
                'status' => $status,
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$status, $status);
        }

        $body = $response->json();

        // QuBeKa envuelve respuestas en {success, data, ...}.
        $data = $body['data'] ?? $body;

        return [
            'session_id' => (int) ($data['session_id'] ?? 0),
            'status' => $data['status'] ?? 'desconocido',
            'resumen' => $data['resumen'] ?? 'Tu aporte quedó registrado.',
        ];
    }
}
