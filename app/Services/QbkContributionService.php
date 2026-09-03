<?php

namespace App\Services;

use App\Exceptions\KuaforiaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de aportes y revisión de conocimiento para QuBeKa.
 *
 * Punto 3 — Aportar:
 *   POST {QUBKA_API_URL}/contribute
 *   Body: {"texto": "...", "origen": "kuestion", "pregunta_previa": "..."} (opcional)
 *   Response: {"session_id": 42, "status": "pendiente_revision", "resumen": "..."}
 *
 * Punto 4 — Revisión humana:
 *   GET  {QUBKA_API_URL}/sesiones-analisis/{sessionId}
 *   POST {QUBKA_API_URL}/sesiones-analisis/{sessionId}/approve
 *   POST {QUBKA_API_URL}/sesiones-analisis/{sessionId}/reject
 *
 * Workspace resuelto desde el token del agente (no se envía en body).
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

    /**
     * Obtener el detalle de una sesión de análisis de QuBeKa.
     *
     * GET {QUBKA_API_URL}/sesiones-analisis/{sessionId}
     *
     * @param  int  $sessionId  ID de la sesión en QuBeKa
     * @param  array  $credential  Credenciales ['api_token' => '...']
     * @return array{session_id: int, status: string, is_simple: bool, pregunta_previa: ?string, nodes: array, resumen: string, created_at: ?string, workspace_nombre: string}
     *
     * @throws KuaforiaException
     */
    public function getSession(int $sessionId, ?array $credential = null): array
    {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/sesiones-analisis/'.$sessionId;

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('QbK getSession timeout', ['session_id' => $sessionId, 'error' => $e->getMessage()]);

            throw new KuaforiaException('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.', 504, $e);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($status === 404) {
                throw new KuaforiaException('Sesión de análisis no encontrada en QuBeKa.', 404);
            }

            Log::warning('QbK getSession failed', [
                'session_id' => $sessionId,
                'status' => $status,
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$status, $status);
        }

        $body = $response->json();
        $data = $body['data'] ?? $body;

        return [
            'session_id' => (int) ($data['session_id'] ?? $sessionId),
            'status' => $data['status'] ?? 'desconocido',
            'is_simple' => (bool) ($data['is_simple'] ?? false),
            'pregunta_previa' => $data['pregunta_previa'] ?? null,
            'nodes' => $data['nodos'] ?? $data['nodes'] ?? [],
            'resumen' => $data['resumen'] ?? '',
            'created_at' => $data['created_at'] ?? null,
            'workspace_nombre' => $data['workspace_nombre'] ?? '',
        ];
    }

    /**
     * Aprobar una sesión de análisis (promueve nodos al grafo activo de QuBeKa).
     *
     * POST {QUBKA_API_URL}/sesiones-analisis/{sessionId}/approve
     * Body: {"textos_ajustados": {"sandbox_1": "..."}} (opcional)
     *
     * @param  int  $sessionId  ID de la sesión en QuBeKa
     * @param  array|null  $textosAjustados  Mapa de nodo_sandbox_id => nuevo_texto (opcional)
     * @param  array  $credential  Credenciales ['api_token' => '...']
     * @return array{success: bool, session_id: int, status: string}
     *
     * Nota: el endpoint POST /approve de QuBeKa responde `status: aprobada` (transitorio)
     * y solo devuelve session_id y status. La sesión pasa a `promocionada` (terminal) cuando
     * corre PromocionarSesionJob unos segundos después, con la creación de nodos/enlaces.
     * nodos_creados/enlaces_creados se mantienen solo por compatibilidad (siempre 0).
     *
     * @throws KuaforiaException
     */
    public function approve(int $sessionId, ?array $textosAjustados = null, ?array $credential = null): array
    {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/sesiones-analisis/'.$sessionId.'/approve';

        $payload = [];
        if ($textosAjustados !== null && $textosAjustados !== []) {
            $payload['textos_ajustados'] = $textosAjustados;
        }

        try {
            $response = Http::timeout(60)
                ->withToken($apiToken)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('QbK approve timeout', ['session_id' => $sessionId, 'error' => $e->getMessage()]);

            throw new KuaforiaException('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.', 504, $e);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($status === 403) {
                throw new KuaforiaException('No tenés permisos para aprobar esta sesión en QuBeKa.', 403);
            }

            if ($status === 404) {
                throw new KuaforiaException('Sesión de análisis no encontrada en QuBeKa.', 404);
            }

            Log::warning('QbK approve failed', [
                'session_id' => $sessionId,
                'status' => $status,
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$status, $status);
        }

        $body = $response->json();
        $data = $body['data'] ?? $body;

        return [
            'success' => (bool) ($data['success'] ?? true),
            'session_id' => (int) ($data['session_id'] ?? $sessionId),
            'status' => $data['status'] ?? 'promocionada',
            'nodos_creados' => (int) ($data['nodos_creados'] ?? 0),
            'enlaces_creados' => (int) ($data['enlaces_creados'] ?? 0),
        ];
    }

    /**
     * Rechazar una sesión de análisis (descarta el sandbox sin promover nodos).
     *
     * POST {QUBKA_API_URL}/sesiones-analisis/{sessionId}/reject
     *
     * @param  int  $sessionId  ID de la sesión en QuBeKa
     * @param  array  $credential  Credenciales ['api_token' => '...']
     * @return array{success: bool, session_id: int, status: string}
     *
     * @throws KuaforiaException
     */
    public function reject(int $sessionId, ?array $credential = null): array
    {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/sesiones-analisis/'.$sessionId.'/reject';

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->post($url);
        } catch (ConnectionException $e) {
            Log::warning('QbK reject timeout', ['session_id' => $sessionId, 'error' => $e->getMessage()]);

            throw new KuaforiaException('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.', 504, $e);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($status === 403) {
                throw new KuaforiaException('No tenés permisos para rechazar esta sesión en QuBeKa.', 403);
            }

            if ($status === 404) {
                throw new KuaforiaException('Sesión de análisis no encontrada en QuBeKa.', 404);
            }

            Log::warning('QbK reject failed', [
                'session_id' => $sessionId,
                'status' => $status,
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$status, $status);
        }

        $body = $response->json();
        $data = $body['data'] ?? $body;

        return [
            'success' => (bool) ($data['success'] ?? true),
            'session_id' => (int) ($data['session_id'] ?? $sessionId),
            'status' => $data['status'] ?? 'rechazada',
        ];
    }
}
