<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 6.2 — API key scoped de Kuaforia por usuario. Se guarda cifrada (cast 'encrypted' en User);
// el tenant_slug se resuelve desde la key (Bloque 6), no se elige de una lista.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('kuaforia_api_key')->nullable()->after('tenant_slug');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kuaforia_api_key');
        });
    }
};
