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

    /*
    |--------------------------------------------------------------------------
    | Reconfirmación periódica (Ola 2, Punto 2)
    |--------------------------------------------------------------------------
    |
    | Umbral en días sin reconfirmación a partir del cual una respuesta QBK
    | se marca como "vencida" (botón Reconfirmar). Fijo por entorno — la
    | decisión del origen §2.3 lo deja fuera del alcance del usuario.
    |
    */

    'reconfirmacion' => [
        'umbral_dias' => env('KUESTION_RECONFIRM_UMBRAL_DIAS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notificaciones por correo (Ola 2, Punto 5)
    |--------------------------------------------------------------------------
    |
    | Ventana de deduplicación en minutos (spec §5): dos correos del mismo evento
    | sobre la misma entidad dentro de la ventana generan un solo envío.
    |
    */

    'email' => [
        'ventana_dedupe_min' => env('KUESTION_EMAIL_DEDUPE_MIN', 30),
    ],

];
