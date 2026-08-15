<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 1.1.7 — Toggle de notificaciones por correo, activo por defecto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // G1: `after('tenant_slug')` eliminado — la columna se dropea en la
            // limpieza del Sistema de Conectores (Fase G); migrate:fresh no debe
            // referenciar una columna que ya no va a existir.
            $table->boolean('email_notifications')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_notifications');
        });
    }
};
