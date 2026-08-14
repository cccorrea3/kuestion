<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date')->unique();
            $table->unsignedInteger('preguntas_activas')->default(0);
            $table->unsignedInteger('preguntas_creadas')->default(0);
            $table->unsignedInteger('cambios_detectados')->default(0);
            $table->unsignedInteger('cambios_revisados')->default(0);
            $table->unsignedInteger('cambios_sin_revisar')->default(0);
            $table->decimal('tiempo_revision_promedio_horas', 8, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
