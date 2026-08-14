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

];
