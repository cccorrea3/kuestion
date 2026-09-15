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

    /*
    |--------------------------------------------------------------------------
    | Subida de documentos (Ola 3, Punto 1)
    |--------------------------------------------------------------------------
    |
    | Límites del spec §4 (propuestos, "A confirmar con Kuestion"): 20 MB,
    | 100 páginas, formatos TXT/MD/PDF/DOCX, timeout de análisis 10 min.
    |
    | Requiere del entorno: upload_max_filesize=25M y post_max_size=30M
    | (hoy 2M/8M — ver plan H4). Sin ese ajuste, la subida falla antes de
    | llegar a la validación de Laravel.
    |
    */

    'documentos' => [
        'max_bytes' => 20 * 1024 * 1024,
        'max_paginas' => 100,
        'formatos' => ['txt', 'md', 'pdf', 'docx'],
        'chunk_caracteres' => 3000,
        'chunk_overlap' => 300,
        'timeout_segundos' => 600,
        'poll_interval_segundos' => 5,
        'upload_intentos' => 3,
        'upload_backoff_base' => 2,
    ],

];
