<?php
// ponytail: mock mínimo para desarrollo
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true);

// Puente MCP (Bloque 8): JSON-RPC 2.0 tools/call con señales estructuradas de prueba.
// Misma forma de respuesta que el puente real: result.content[].text con JSON string.
if ($path === '/api/v1/mcp') {
    $method = $input['method'] ?? '';
    $name = $input['params']['name'] ?? '';
    $args = $input['params']['arguments'] ?? [];

    if ($method !== 'tools/call') {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $input['id'] ?? null,
            'error' => ['code' => -32601, 'message' => 'Method not found'],
        ]);
        exit;
    }

    // get_client_context (Sistema de Conectores RAG — Fase B): identidad del tenant.
    // Contrato P3: key inválida → HTTP 401 con JSON PLANO (rompe el sobre JSON-RPC);
    // key válida → content[0].text como STRING JSON con data.tenant.slug/name.
    if ($name === 'get_client_context') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (! str_starts_with($auth, 'Bearer kfr_')) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid or expired API key']);
            exit;
        }

        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $input['id'] ?? null,
            'result' => [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'success' => true,
                        'data' => [
                            'tenant' => ['slug' => 'ispend', 'name' => 'Ispend'],
                            'scopes' => ['questions:read'],
                            'mcp_user_id' => null,
                            'expires_at' => null,
                            'knowledge_workspace' => ['id' => null, 'name' => null],
                            // G7 — Kuaforia extendió el contrato: default_workspace {id, name, slug}.
                            'default_workspace' => [
                                'id' => 'ws-ispend',
                                'name' => 'Workspace Ispend',
                                'slug' => 'ispend',
                            ],
                        ],
                    ]),
                ]],
                'isError' => false,
            ],
        ]);
        exit;
    }

    $signals = match ($name) {
        'get_workspace_health' => [
            'workspace_id' => $args['workspace_id'] ?? null,
            'status' => 'healthy',
            'healthy' => true,
            'score' => 85,
        ],
        'get_dependency_health_report' => [
            'workspace_id' => $args['workspace_id'] ?? null,
            'dependencies' => [
                ['name' => 'Kuaforia', 'status' => 'ok'],
                ['name' => 'Base de datos', 'status' => 'ok'],
            ],
        ],
        'get_case' => [
            'case_id' => $args['case_id'] ?? null,
            'status' => 'open',
        ],
        default => null,
    };

    if ($signals === null) {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $input['id'] ?? null,
            'error' => ['code' => -32602, 'message' => "Unknown tool: {$name}"],
        ]);
        exit;
    }

    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $input['id'] ?? null,
        'result' => [
            'content' => [['type' => 'text', 'text' => json_encode($signals)]],
            'isError' => false,
        ],
    ]);
    exit;
}

// Consulta REST vigente (sin cambios).
$question = $input['question'] ?? '';

$answers = [
    'capital de francia' => 'París es la capital de Francia, conocida como la Ciudad de la Luz.',
    'presidente de españa' => 'El presidente del Gobierno de España es Pedro Sánchez.',
    'que es laravel' => 'Laravel es un framework PHP para desarrollo web con arquitectura MVC.',
];

$answer = 'Lo siento, no tengo información sobre esa pregunta.';
foreach ($answers as $key => $val) {
    if (str_contains(mb_strtolower($question), $key)) {
        $answer = $val;
        break;
    }
}

echo json_encode([
    'answer' => $answer,
    'confidence' => 85.0,
    'sources' => [['title' => 'Fuente de prueba', 'url' => 'https://ejemplo.cl']],
]);
