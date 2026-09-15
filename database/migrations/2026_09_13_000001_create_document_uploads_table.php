<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ola 3, Punto 1 — B.4: persistencia local de la carga de documentos.
 *
 * Mismo rol que contribution_drafts para aportes cortos: recuperación ante
 * fallo (B.6/B.7, FB-9) y base del aviso de duplicado por hash (B.2/FB-8).
 * Los chunks NO se persisten más allá del envío (§4 del spec).
 *
 * Patrón de claves del proyecto (igual que contribution_drafts):
 * user_id → users.uuid; repository_id → repositories.id (uuid, nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->uuid('repository_id')->nullable();
            $table->string('nombre');
            $table->string('hash', 64)->index();
            $table->string('estado', 30)->default('procesando')->index();
            $table->unsignedBigInteger('qbk_session_id')->nullable()->index();
            $table->unsignedInteger('chunks_totales')->default(0);
            $table->unsignedInteger('chunks_procesados')->default(0);
            $table->unsignedInteger('nodos_propuestos')->default(0);
            $table->string('contexto', 500)->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('uuid')->on('users')->cascadeOnDelete();
            $table->foreign('repository_id')->references('id')->on('repositories')->nullOnDelete();
            $table->index(['user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_uploads');
    }
};
