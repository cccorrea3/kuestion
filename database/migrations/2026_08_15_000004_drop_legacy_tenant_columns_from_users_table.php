<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// G1 (Sistema de Conectores RAG — limpieza): desde las Fases C–F ninguna parte de la
// app lee `users.tenant_slug` ni `users.kuaforia_api_key` (verificado con grep antes
// de migrar). La conexión vive en `repositories` (credencial cifrada + resolved_tenant_*).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tenant_slug', 'kuaforia_api_key']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tenant_slug')->nullable()->index();
            $table->text('kuaforia_api_key')->nullable();
        });
    }
};
