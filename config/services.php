<?php

return [

    'kuaforia' => [
        'base_url' => env('KUAFORIA_BASE_URL', 'http://localhost:8080'),
        'api_key' => env('KUAFORIA_API_KEY'),
        // 6.1 — Vía de resolución de tenant desde API key del usuario (kfr_):
        // 'rest' (endpoint liviano de Kuaforia) | 'mcp' (puente MCP con stateless).
        'tenant_resolution' => env('KUAFORIA_TENANT_RESOLUTION', 'rest'),
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
