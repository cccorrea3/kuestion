<?php

namespace App\Services;

use App\Exceptions\KuaforiaException;
use App\Services\Explicacion\ExplicacionNormalizer;
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

        $result = [
            'session_id' => (int) ($data['session_id'] ?? 0),
            'status' => $data['status'] ?? 'desconocido',
            'resumen' => $data['resumen'] ?? 'Tu aporte quedó registrado.',
        ];

        // Ola 2 Punto 4 — análisis síncrono: explicación del nodo principal si viene.
        if (isset($data['explicacion_nodo_principal']) && is_array($data['explicacion_nodo_principal'])) {
            $result['explicacion'] = ExplicacionNormalizer::fromArray($data['explicacion_nodo_principal']);
        }

        return $result;
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

        $result = [
            'session_id' => (int) ($data['session_id'] ?? $sessionId),
            'status' => $data['status'] ?? 'desconocido',
            'is_simple' => (bool) ($data['is_simple'] ?? false),
            'pregunta_previa' => $data['pregunta_previa'] ?? null,
            'nodes' => $data['nodos'] ?? $data['nodes'] ?? [],
            'resumen' => $data['resumen'] ?? '',
            'created_at' => $data['created_at'] ?? null,
            'workspace_nombre' => $data['workspace_nombre'] ?? '',
            // Ola 2, Punto 5 — C.4: identidad del revisor (contrato v1.3, detalle §show).
            // Alimenta el copy "aprobado por [nombre]" del correo de decisión.
            'revisado_por_email' => $data['revisado_por_email'] ?? null,
            'revisado_por_nombre' => $data['revisado_por_nombre'] ?? null,
            'autor_nombre' => $data['autor_nombre'] ?? null,
        ];

        // Ola 2 Punto 4 — A.2/A.3/A.4: explicación por nodo normalizada.
        // Regla Q4.5 (precedencia): explicacion es la única fuente de display;
        // si el nodo no la trae, queda null/sin_detalle (nunca fallback a
        // confianza/justificacion_ia).
        $result['nodes'] = array_map(
            fn (array $node): array => $this->normalizeNode($node),
            $result['nodes'],
        );

        return $result;
    }

    /**
     * Ola 2 Punto 4 — A.2/A.3/A.4: normaliza un nodo del detalle agregando su
     * explicación. Sin metadatos (sesiones pre-despliegue): sin_detalle —
     * nunca null para que la UI siempre reciba la estructura tipada.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node): array
    {
        $node['explicacion'] = isset($node['explicacion']) && is_array($node['explicacion'])
            ? ExplicacionNormalizer::fromArray($node['explicacion'])
            : ExplicacionNormalizer::sinDetalle();

        return $node;
    }

    /**
     * Aprobar una sesión de análisis (promueve nodos al grafo activo de QuBeKa).
     *
     * POST {QUBKA_API_URL}/sesiones-analisis/{sessionId}/approve
     * Body: {"textos_ajustados": {"sandbox_1": "..."}, "revisado_por_email": "...", "revisado_por_nombre": "..."} (opcionales)
     *
     * @param  int  $sessionId  ID de la sesión en QuBeKa
     * @param  array|null  $textosAjustados  Mapa de nodo_sandbox_id => nuevo_texto (opcional)
     * @param  array  $credential  Credenciales ['api_token' => '...']
     * @param  array|null  $revisadoPor  Ola 2, Punto 5 — C.4: identidad del revisor autenticado
     *                                   en Kuestion ['email' => ..., 'nombre' => ...] (atribución declarada, contrato v1.3).
     * @return array{success: bool, session_id: int, status: string}
     *
     * Nota: el endpoint POST /approve de QuBeKa responde `status: aprobada` (transitorio)
     * y solo devuelve session_id y status. La sesión pasa a `promocionada` (terminal) cuando
     * corre PromocionarSesionJob unos segundos después, con la creación de nodos/enlaces.
     * nodos_creados/enlaces_creados se mantienen solo por compatibilidad (siempre 0).
     *
     * @throws KuaforiaException
     */
    public function approve(int $sessionId, ?array $textosAjustados = null, ?array $credential = null, ?array $revisadoPor = null): array
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

        // Ola 2, Punto 5 — C.4: atribución declarada del revisor (contrato v1.3).
        if ($revisadoPor !== null) {
            if (! empty($revisadoPor['email'])) {
                $payload['revisado_por_email'] = $revisadoPor['email'];
            }
            if (! empty($revisadoPor['nombre'])) {
                $payload['revisado_por_nombre'] = $revisadoPor['nombre'];
            }
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
     * Body: {"revisado_por_email": "...", "revisado_por_nombre": "..."} (opcionales, C.4)
     *
     * @param  int  $sessionId  ID de la sesión en QuBeKa
     * @param  array  $credential  Credenciales ['api_token' => '...']
     * @param  array|null  $revisadoPor  Ola 2, Punto 5 — C.4: identidad del revisor autenticado
     * @return array{success: bool, session_id: int, status: string}
     *
     * @throws KuaforiaException
     */
    public function reject(int $sessionId, ?array $credential = null, ?array $revisadoPor = null): array
    {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/sesiones-analisis/'.$sessionId.'/reject';

        // Ola 2, Punto 5 — C.4: atribución declarada del revisor (contrato v1.3).
        $payload = [];
        if ($revisadoPor !== null) {
            if (! empty($revisadoPor['email'])) {
                $payload['revisado_por_email'] = $revisadoPor['email'];
            }
            if (! empty($revisadoPor['nombre'])) {
                $payload['revisado_por_nombre'] = $revisadoPor['nombre'];
            }
        }

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->post($url, $payload);
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

    /**
     * Reconfirmar un nodo de QuBeKa (Ola 2, Punto 2 — Fase A, A.2).
     *
     * PATCH {QUBKA_API_URL}/nodos/{nodeId}/reconfirmar
     *
     * Contrato de referencia: docs/CONTRATO_API_OLA2.md §5.1 (implementado en QuBeKa).
     * - Actualiza fecha_ultima_confirmacion + ultimo_confirmador_id; no toca version ni
     *   actualizado_en (no rompe el hash de vigilancia). Idempotente.
     * - Un llamado por nodo: Kuestion itera sobre los sources de la versión actual.
     * - Permisos: autor del nodo o revisor del workspace (403 en otro caso).
     *
     * @param  int|string  $nodeId  ID del nodo en QuBeKa (node_id de sources[])
     * @param  array|null  $credential  Credenciales ['api_token' => '...']
     * @return array{node_id: int|string, fecha_ultima_confirmacion: string|null, ultimo_confirmador_id: int|string|null}
     *
     * @throws KuaforiaException
     */
    public function reconfirmarNodo(int|string $nodeId, ?array $credential = null): array
    {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/nodos/'.$nodeId.'/reconfirmar';

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->patch($url);
        } catch (ConnectionException $e) {
            Log::warning('QbK reconfirmar timeout', ['node_id' => $nodeId, 'error' => $e->getMessage()]);

            throw new KuaforiaException('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.', 504, $e);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($status === 403) {
                throw new KuaforiaException('No tenés permiso para reconfirmar este conocimiento en QuBeKa.', 403);
            }

            if ($status === 404) {
                throw new KuaforiaException('Este conocimiento ya no está disponible para reconfirmar.', 404);
            }

            Log::warning('QbK reconfirmar failed', [
                'node_id' => $nodeId,
                'status' => $status,
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$status, $status);
        }

        $body = $response->json();
        $data = $body['data'] ?? $body;

        return [
            'node_id' => $data['node_id'] ?? $nodeId,
            'fecha_ultima_confirmacion' => $data['fecha_ultima_confirmacion'] ?? null,
            'ultimo_confirmador_id' => $data['ultimo_confirmador_id'] ?? null,
        ];
    }

    /**
     * Listar sesiones pendientes de revisión del workspace (fuente de verdad de la bandeja).
     *
     * GET {QUBKA_API_URL}/sesiones-analisis?estado=pendientes&page=&per_page=
     *
     * Contrato de referencia: docs/CONTRATO_API_OLA2.md §4.1.
     * Los campos reales devueltos por QuBeKa usan el naming del contrato:
     *   creado_en, contenido_entrada (no fecha_creacion / texto_original_del_aporte).
     *   estado_decisión = cerrado_en (cuando existe).
     *
     * @param  int  $page  Página (default 1)
     * @param  int  $perPage  Cantidad por página (default 20, max 100)
     * @param  string  $estado  Filtro: 'pendientes' | 'historial'
     * @param  array|null  $credential  Credenciales ['api_token' => '...']
     * @return array{
     *     success: bool,
     *     data: array{
     *         items: array<int, array{
     *             session_id: int|string,
     *             status: string,
     *             fecha_creacion: string|null,
     *             texto_original_del_aporte: string|null,
     *             resumen_clasificacion: string|null,
     *             is_simple: bool,
     *             pregunta_previa: string|null,
     *             autor_email: string|null,
     *             autor_nombre: string|null,
     *             fecha_decision: string|null,
     *         }>,
     *         total: int,
     *         page: int,
     *         per_page: int,
     *     },
     * }
     *
     * @throws KuaforiaException
     */
    public function listSessions(
        int $page = 1,
        int $perPage = 20,
        string $estado = 'pendientes',
        ?array $credential = null,
    ): array {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        if (! in_array($estado, ['pendientes', 'historial'], true)) {
            $estado = 'pendientes';
        }

        $perPage = min($perPage, 100);

        $url = rtrim(config('services.qubeka.api_url'), '/').'/sesiones-analisis';

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->get($url, [
                    'estado' => $estado,
                    'page' => $page,
                    'per_page' => $perPage,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('QbK listSessions timeout', ['error' => $e->getMessage()]);

            throw new KuaforiaException('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.', 504, $e);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($status === 403) {
                throw new KuaforiaException('No tenés permiso de lectura en este workspace de QuBeKa.', 403);
            }

            Log::warning('QbK listSessions failed', [
                'status' => $status,
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$status, $status);
        }

        $body = $response->json() ?? [];
        $data = $body['data'] ?? $body;
        $meta = $body['meta'] ?? [];

        // QuBeKa real: data es un arreglo plano de ítems y la paginación vive en meta
        // (helper ApiResponse::paginated). Los fakes/tests usan data.items + data.total.
        if (isset($data['items']) && is_array($data['items'])) {
            $items = $data['items'];
            $total = (int) ($data['total'] ?? $meta['total'] ?? count($items));
        } elseif (is_array($data) && array_is_list($data)) {
            $items = $data;
            $total = (int) ($meta['total'] ?? count($items));
        } else {
            $items = [];
            $total = 0;
        }

        return [
            'success' => (bool) ($body['success'] ?? true),
            'data' => [
                'items' => $this->normalizeSessionItems($items),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Ola 2, Punto 5 — D.2: miembros del workspace con su rol, para armar los
     * destinatarios del correo "aporte pendiente de revisión".
     *
     * GET {QUBKA_API_URL}/workspaces/{workspaceId}/miembros (contrato §8.2, v1.4;
     * implementado por QuBeKa en commits 491d5ae/efc325f).
     *
     * @param  string  $workspaceId  Workspace resuelto del repo (resolved_workspace_id)
     * @param  array|null  $credential  Credenciales ['api_token' => '...']
     * @return array<int, array{user_id: int|string, nombre: string, email: string, rol: string}>
     *
     * @throws KuaforiaException
     */
    public function listarMiembros(string $workspaceId, ?array $credential = null): array
    {
        $apiToken = $credential['api_token'] ?? null;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KuaforiaException('Credencial de QuBeKa sin token de agente.');
        }

        $url = rtrim(config('services.qubeka.api_url'), '/').'/workspaces/'.$workspaceId.'/miembros';

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('QbK listarMiembros timeout', ['workspace_id' => $workspaceId, 'error' => $e->getMessage()]);

            throw new KuaforiaException('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.', 504, $e);
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401) {
                throw new KuaforiaException('El token de QuBeKa es inválido o fue revocado.', 401);
            }

            if ($status === 403) {
                throw new KuaforiaException('No tenés permiso para ver los miembros de este workspace en QuBeKa.', 403);
            }

            Log::warning('QbK listarMiembros failed', [
                'workspace_id' => $workspaceId,
                'status' => $status,
                'body' => $response->body(),
            ]);

            throw new KuaforiaException('QuBeKa respondió con error: '.$status, $status);
        }

        $body = $response->json() ?? [];
        $data = $body['data'] ?? $body;
        $miembros = $data['miembros'] ?? [];

        return array_values(array_map(fn (array $m): array => [
            'user_id' => $m['user_id'] ?? 0,
            'nombre' => $m['nombre'] ?? '',
            'email' => $m['email'] ?? '',
            'rol' => $m['rol'] ?? '',
        ], $miembros));
    }

    /**
     * Normalizar ítems crudos de QuBeKa al formato interno de la bandeja.
     *
     * Mapeo según contrato §4.1 (nombres reales del endpoint):
     *   creado_en            -> fecha_creacion
     *   contenido_entrada    -> texto_original_del_aporte
     *   resumen              -> resumen_clasificacion
     *   pregunta_previa      -> pregunta_previa (igual)
     *   cerrado_en           -> fecha_decision
     *   autor_email/nombre   -> autor_email/autor_nombre (sujeto a B2; nullable)
     */
    private function normalizeSessionItems(array $items): array
    {
        return array_map(function (array $item) {
            return [
                'session_id' => $item['session_id'] ?? 0,
                'status' => $item['status'] ?? 'desconocido',
                'fecha_creacion' => $item['fecha_creacion'] ?? $item['creado_en'] ?? null,
                'texto_original_del_aporte' => $item['texto_original_del_aporte'] ?? $item['contenido_entrada'] ?? null,
                'resumen_clasificacion' => $item['resumen_clasificacion'] ?? $item['resumen'] ?? null,
                'is_simple' => (bool) ($item['is_simple'] ?? false),
                'pregunta_previa' => $item['pregunta_previa'] ?? null,
                'autor_email' => $item['autor_email'] ?? null,
                'autor_nombre' => $item['autor_nombre'] ?? null,
                'fecha_decision' => $item['fecha_decision'] ?? $item['cerrado_en'] ?? null,
            ];
        }, $items);
    }
}
