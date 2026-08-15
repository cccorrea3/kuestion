<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A1 — Sistema de Conectores RAG: tabla de repositorios conectados.
// Un repositorio = una conexión concreta a un conector (hoy solo Kuaforia) con su
// credencial cifrada y la identidad resuelta al validar (tenant slug/name, workspace).
// Referencia: docs/kuestion-sistema-conectores-referencia.md §3.1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('connector_type', 50)->default('kuaforia');
            $table->string('name')->nullable();
            // Credencial del conector: array serializado a JSON y cifrado (cast encrypted:array).
            $table->longText('credential')->nullable();
            $table->string('resolved_tenant_slug', 100)->nullable();
            $table->string('resolved_tenant_name', 255)->nullable();
            $table->string('resolved_workspace_id', 100)->nullable();
            // Tri-estado (decisión cerrada §9.4): active | invalid | revoked.
            $table->string('status', 20)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('uuid')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'connector_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
