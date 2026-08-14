<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retención de versiones
    |--------------------------------------------------------------------------
    |
    | Política (1.6): las preguntas activas conservan TODAS sus versiones; las
    | preguntas archivadas (soft-deleted) conservan solo las últimas N.
    |
    */

    'retention' => [
        'archived_versions' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features (rollout)
    |--------------------------------------------------------------------------
    |
    | 14.3 — Grafo de relaciones. APAGADO por defecto (resolución de revisión §6.2):
    | un grafo casi vacío en el lanzamiento resta más de lo que suma. Activarlo
    | cuando el piloto de Ispend acumule las ~10 preguntas relacionadas que
    | recomienda el maestro.
    |
    */

    'features' => [
        'relations_graph' => env('KUESTION_FEATURE_RELATIONS_GRAPH', false),
    ],

];
