<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 9.1 — Tokens de agente para el MCP Server propio de Kuestion (Bloque 9).
// Solo se guarda el hash bcrypt del token plano (no recuperable; regenerar si se pierde).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->string('token_hash', 60);
            // Sin default en la columna (MySQL < 8.0.13 no permite default en JSON);
            // el default ['read'] lo aplica Eloquent vía $attributes del modelo.
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('uuid')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tokens');
    }
};
