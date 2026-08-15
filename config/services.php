<?php

return [

    'kuaforia' => [
        'base_url' => env('KUAFORIA_BASE_URL', 'http://localhost:8080'),
        // Key compartida para la consulta REST. Sigue vigente hasta que Kuaforia confirme
        // que la kfr_ del usuario autentica /api/consult (G6 condicional, pregunta 8.1).
        'api_key' => env('KUAFORIA_API_KEY'),
        // 8.3 — Señales vía MCP (Bloque 8).
        // mcp_url default: base_url . '/api/v1/mcp' (puente HTTP MCP de Kuaforia).
        'mcp_url' => env('KUAFORIA_MCP_URL', rtrim(env('KUAFORIA_BASE_URL', 'http://localhost:8080'), '/').'/api/v1/mcp'),
        // Fallback de las señales cuando el repo no tiene resolved_workspace_id. Las señales
        // usan la credencial del repositorio (E1); mcp_api_key queda como superficie de
        // confianza residual (revisar el día que haya más de un tenant sensible simultáneo).
        'mcp_api_key' => env('KUAFORIA_MCP_API_KEY', env('KUAFORIA_API_KEY')),
        // Mapeo nombre de tool MCP → método de StructuredSignalProviderInterface.
        // Un cambio de catálogo de Kuaforia se resuelve ajustando esta config.
        'mcp_tools' => [
            'get_workspace_health' => 'getWorkspaceHealth',
            'get_dependency_health_report' => 'getDependencyHealthReport',
            'get_case' => 'getCaseDetails',
        ],
        // tenant_slug => workspace_id (opcional). Fallback mientras Kuaforia no devuelva
        // el workspace_id por defecto en get_client_context (P2/P3; se elimina en G7
        // cuando el contrato lo incluya).
        'workspace_map' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
