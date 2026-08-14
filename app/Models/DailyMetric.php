<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyMetric extends Model
{
    protected $fillable = [
        'metric_date',
        'preguntas_activas',
        'preguntas_creadas',
        'cambios_detectados',
        'cambios_revisados',
        'cambios_sin_revisar',
        'tiempo_revision_promedio_horas',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'tiempo_revision_promedio_horas' => 'decimal:2',
        ];
    }
}
