<?php

// Mock mínimo de QuBeKa para desarrollo local.
// Correr con: php -S localhost:8002 qbk-mock.php
// Configurar: QUBKA_API_URL=http://localhost:8002

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true);

// GET /agent/me — Resolución de identidad del agente (Ola 1 Punto 1 — Fase 4).
if ($path === '/agent/me' && $method === 'GET') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (! str_starts_with($auth, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    echo json_encode([
        'workspace_id' => 1,
        'workspace_nombre' => 'Workspace de Desarrollo',
        'user_id' => 1,
        'user_nombre' => 'Dev User',
        'agente_nombre' => 'Kuestion Connector',
        'scopes' => ['api:read'],
    ]);
    exit;
}

// POST /query — Motor de Consulta (Ola 1 Punto 1 — Fase 3).
if ($path === '/query' && $method === 'POST') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (! str_starts_with($auth, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $question = $input['question'] ?? '';

    if ($question === '') {
        http_response_code(422);
        echo json_encode(['error' => 'question is required']);
        exit;
    }

    // Simulación de respuestas
    $lower = mb_strtolower($question);

    if (str_contains($lower, 'laravel') || str_contains($lower, 'framework')) {
        echo json_encode([
            'answer' => 'Laravel es un framework PHP open-source para desarrollo web con arquitectura MVC, creado por Taylor Otwell. Ofrece herramientas para routing, ORM (Eloquent), autenticación, y más.',
            'confidence' => 0.5,
            'sources' => [
                [
                    'node_id' => 'NK-001',
                    'tipo' => 'N-K',
                    'estado_validacion' => 'validada',
                    'fecha_ultima_validacion' => '2026-07-10T00:00:00Z',
                    'texto_preview' => 'Laravel es un framework PHP para desarrollo web...',
                ],
            ],
            'found' => true,
        ]);
        exit;
    }

    if (str_contains($lower, 'no encontr') || str_contains($lower, 'nada')) {
        echo json_encode([
            'answer' => 'No encontré información relevante en la base de conocimiento.',
            'confidence' => 0.0,
            'sources' => [],
            'found' => false,
        ]);
        exit;
    }

    // Respuesta genérica
    echo json_encode([
        'answer' => "Esta es una respuesta de prueba de QuBeKa para: \"{$question}\"",
        'confidence' => 0.5,
        'sources' => [
            [
                'node_id' => 'NK-100',
                'tipo' => 'N-K',
                'estado_validacion' => 'validada',
                'fecha_ultima_validacion' => '2026-08-01T00:00:00Z',
                'texto_preview' => 'Contenido de prueba del mock de QuBeKa...',
            ],
        ],
        'found' => true,
    ]);
    exit;
}

// 404 para rutas no implementadas
http_response_code(404);
echo json_encode(['error' => 'Not found', 'path' => $path]);
