<?php

namespace App\Services\Mcp;

use App\Models\AgentToken;
use App\Models\Question;

/**
 * Dispatcher MCP puro (9.4/9.5 — Bloque 9): traduce mensajes JSON-RPC 2.0 a
 * respuestas, sin tocar stdio. Testeable sin proceso.
 *
 * Hand-rolled mínimo (sin SDK PHP de MCP), consistente con la convención del
 * proyecto ("crear, no instalar"). Soporta initialize, ping, tools/list y
 * tools/call; las notificaciones (notifications/*) no reciben respuesta.
 *
 * Las tools son de solo lectura y scoped por el user_id del token autenticado.
 * No se exponen señales de Kuaforia (resolución de revisión: el agente externo
 * le habla directo al MCP de Kuaforia — "un MCP, un agente, por plataforma").
 */
class McpServer
{
    public const PROTOCOL_VERSION = '2024-11-05';

    public const SERVER_NAME = 'kuestion';

    public const SERVER_VERSION = '1.0.0';

    public function __construct(
        private readonly AgentToken $token,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null para notificaciones (sin respuesta)
     */
    public function handleMessage(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->rpcError($id, -32600, 'Invalid request: falta method');
        }

        // Notificaciones: JSON-RPC no espera respuesta.
        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => $message['params']['protocolVersion'] ?? self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            ]),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->callTool($id, $message['params'] ?? []),
            default => $this->rpcError($id, -32601, 'Method not found: '.$method),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'list_questions',
                'description' => 'Lista las preguntas del usuario autenticado. Filtros opcionales por estado, tag o búsqueda de texto.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['active', 'archived'], 'description' => 'Filtra por estado'],
                        'tag' => ['type' => 'string', 'description' => 'Filtra por tag exacto'],
                        'search' => ['type' => 'string', 'description' => 'Búsqueda por texto de la pregunta'],
                    ],
                ],
            ],
            [
                'name' => 'get_question_details',
                'description' => 'Devuelve una pregunta con su versión actual (respuesta, confianza y fuentes).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'question_id' => ['type' => 'string', 'description' => 'UUID de la pregunta'],
                    ],
                    'required' => ['question_id'],
                ],
            ],
            [
                'name' => 'list_unreviewed_changes',
                'description' => 'Lista las preguntas con cambios detectados aún no revisados (versión actual incluida).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Cantidad máxima (default 20)'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(int|string|null $id, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (! is_string($name) || ! is_array($arguments)) {
            return $this->rpcError($id, -32602, 'Invalid params: name y arguments son requeridos');
        }

        return match ($name) {
            'list_questions' => $this->listQuestions($id, $arguments),
            'get_question_details' => $this->getQuestionDetails($id, $arguments),
            'list_unreviewed_changes' => $this->listUnreviewedChanges($id, $arguments),
            default => $this->rpcError($id, -32602, 'Unknown tool: '.$name),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listQuestions(int|string|null $id, array $arguments): array
    {
        $query = Question::query()->where('user_id', $this->token->user_id);

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }
        if (! empty($arguments['tag'])) {
            $query->whereJsonContains('tags', $arguments['tag']);
        }
        if (! empty($arguments['search'])) {
            $query->search((string) $arguments['search']);
        }

        $questions = $query->withCount('versions')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'question_text', 'status', 'tags', 'review_frequency', 'has_unreviewed_changes', 'last_consulted_at', 'created_at']);

        return $this->toolResult($id, [
            'total' => $questions->count(),
            'questions' => $questions->map(fn (Question $q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'status' => $q->status,
                'tags' => $q->tags,
                'review_frequency' => $q->review_frequency,
                'has_unreviewed_changes' => $q->has_unreviewed_changes,
                'version_count' => $q->versions_count,
                'created_at' => $q->created_at?->toIso8601String(),
                'last_consulted_at' => $q->last_consulted_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getQuestionDetails(int|string|null $id, array $arguments): array
    {
        $questionId = $arguments['question_id'] ?? null;

        if (! is_string($questionId) || $questionId === '') {
            return $this->rpcError($id, -32602, 'Invalid params: question_id es requerido');
        }

        $question = Question::query()
            ->where('user_id', $this->token->user_id)
            ->with('currentVersion')
            ->find($questionId);

        if (! $question) {
            return $this->toolResult($id, ['error' => 'Pregunta no encontrada para este usuario'], isError: true);
        }

        return $this->toolResult($id, [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'status' => $question->status,
            'tags' => $question->tags,
            'review_frequency' => $question->review_frequency,
            'has_unreviewed_changes' => $question->has_unreviewed_changes,
            'created_at' => $question->created_at?->toIso8601String(),
            'current_version' => $question->currentVersion ? [
                'version_number' => $question->currentVersion->version_number,
                'answer_text' => $question->currentVersion->answer_text,
                'confidence' => (float) $question->currentVersion->confidence,
                'sources' => $question->currentVersion->sources,
                'created_at' => $question->currentVersion->created_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listUnreviewedChanges(int|string|null $id, array $arguments): array
    {
        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 100);

        $questions = Question::query()
            ->where('user_id', $this->token->user_id)
            ->where('has_unreviewed_changes', true)
            ->with('currentVersion')
            ->orderByDesc('last_change_detected_at')
            ->limit($limit)
            ->get(['id', 'question_text', 'last_change_detected_at']);

        return $this->toolResult($id, [
            'total' => $questions->count(),
            'changes' => $questions->map(fn (Question $q) => [
                'question_id' => $q->id,
                'question_text' => $q->question_text,
                'last_change_detected_at' => $q->last_change_detected_at?->toIso8601String(),
                'current_version' => $q->currentVersion?->version_number,
                'answer_text' => $q->currentVersion?->answer_text,
            ])->values(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toolResult(int|string|null $id, array $data, bool $isError = false): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                'isError' => $isError,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function result(int|string|null $id, array|\stdClass $data): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rpcError(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
