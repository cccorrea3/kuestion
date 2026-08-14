<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 10.2 — Lista para almacenar señales estructuradas futuras (Bloque 8 no persiste
// señales aún; la tabla queda preparada). Misma convención que answer_versions:
// uuid PK + FK cascade + índice compuesto para consultas por pregunta/tipo/fecha.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structured_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('question_id');
            $table->string('signal_type', 50);
            $table->json('payload');
            $table->timestamp('detected_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->index(['question_id', 'signal_type', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structured_signals');
    }
};
