<?php

use App\Services\IdentityResolver;
use App\Services\KuaforiaMcpProvider;
use App\Services\KuaforiaService;
use App\Services\QbkIdentityResolver;
use App\Services\QbkService;

/*
|--------------------------------------------------------------------------
| Registro de conectores RAG
|--------------------------------------------------------------------------
|
| Sistema de Conectores RAG (docs/kuestion-sistema-conectores-referencia.md §1.3).
| Cada conector declara su ficha y las clases que implementan las interfaces
| (identidad, consulta RAG, señales estructuradas).
|
| Decisiones del plan de implementación:
| - A2: las clases viven en app/Services/ (no se crea app/Connectors/ hasta que
|   exista un segundo conector real).
| - identity_resolver (App\Services\IdentityResolver) se implementa en la Fase B
|   del plan; acá ya queda declarado (::class es solo un FQCN string).
| - P6: no se construye el selector de tipo de conector — esta config queda
|   preparada para cuando exista un segundo conector.
|
*/

return [
    'kuaforia' => [
        'display_name' => 'Kuaforia',
        'description' => 'Plataforma RAG de Kuaforia',
        'auth_fields' => [
            [
                'key' => 'api_key',
                'label' => 'API key',
                // §6.1 — ayuda contextual específica al conector.
                'hint' => '¿Cómo obtengo mi API key?',
            ],
        ],
        // §6.1 — URL de ayuda del conector (null hasta tener el link oficial de Kuaforia).
        'help_url' => null,
        'identity_resolver' => IdentityResolver::class,
        'rag_provider' => KuaforiaService::class,
        'signal_provider' => KuaforiaMcpProvider::class,
    ],

    // Ola 1, Punto 1 — Conector QuBeKa.
    // Contrato: POST /query (body solo question), GET /agent/me (Bearer token Sanctum).
    // signal_provider = null: QuBeKa no expone señales estructuradas (fuera de alcance).
    'qbk' => [
        'display_name' => 'QuBeKa',
        'description' => 'Plataforma de conocimiento QuBeKa',
        'auth_fields' => [
            [
                'key' => 'api_token',
                'label' => 'Token de agente',
                'hint' => 'Token Sanctum con scope api:read (y api:write para aportes).',
            ],
        ],
        'help_url' => null,
        'identity_resolver' => QbkIdentityResolver::class,
        'rag_provider' => QbkService::class,
        'signal_provider' => null,
    ],
];
