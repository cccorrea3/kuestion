<?php

return [

    'kuaforia' => [
        'base_url' => env('KUAFORIA_BASE_URL', 'http://localhost:8080'),
        'api_key' => env('KUAFORIA_API_KEY'),
        // 6.1 — Vía de resolución de tenant desde API key del usuario (kfr_):
        // 'rest' (endpoint liviano de Kuaforia) | 'mcp' (puente MCP con stateless).
        'tenant_resolution' => env('KUAFORIA_TENANT_RESOLUTION', 'rest'),
        // 8.3 — Señales vía MCP (Bloque 8).
        // mcp_url default: base_url . '/api/v1/mcp' (puente HTTP MCP de Kuaforia).
        'mcp_url' => env('KUAFORIA_MCP_URL', rtrim(env('KUAFORIA_BASE_URL', 'http://localhost:8080'), '/').'/api/v1/mcp'),
        // Superficie de confianza (resolución de revisión, Hallazgo 2): por defecto la
        // misma key compartida de la consulta REST. Aceptada para el piloto; revisar el
        // día que haya más de un tenant con datos sensibles conectado simultáneamente.
        'mcp_api_key' => env('KUAFORIA_MCP_API_KEY', env('KUAFORIA_API_KEY')),
        // Mapeo nombre de tool MCP → método de StructuredSignalProviderInterface.
        // Un cambio de catálogo de Kuaforia se resuelve ajustando esta config.
        'mcp_tools' => [
            'get_workspace_health' => 'getWorkspaceHealth',
            'get_dependency_health_report' => 'getDependencyHealthReport',
            'get_case' => 'getCaseDetails',
        ],
        // tenant_slug => workspace_id (opcional). Fallback mientras Kuaforia no devuelva
        // el workspace_id por defecto en la validación apikey→tenant (§1.3, Hallazgo 1).
        'workspace_map' => [],
        'tenants' => [
            ['slug' => 'ispend', 'name' => 'Ispend'],
        ],
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
