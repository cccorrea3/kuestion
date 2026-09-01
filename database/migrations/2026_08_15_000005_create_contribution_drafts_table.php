<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de borradores de aportes de conocimiento (Ola 1, Punto 3 — Fase 4).
 *
 * Cuando el servicio de QuBeKa falla al recibir un aporte, se guarda localmente
 * para que el usuario pueda reintentar sin perder su texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->uuid('repository_id')->nullable();
            $table->text('texto');
            $table->text('pregunta_previa')->nullable();
            $table->string('status', 20)->default('pending_retry'); // pending_retry | sent | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('uuid')->on('users')->cascadeOnDelete();
            $table->foreign('repository_id')->references('id')->on('repositories')->nullOnDelete();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_drafts');
    }
};
