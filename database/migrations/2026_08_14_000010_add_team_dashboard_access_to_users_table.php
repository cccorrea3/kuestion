<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 12.1 — Acceso al panorama de equipo. Solución TEMPORAL (maestro): será
// reemplazada por un sistema de roles. Convención del proyecto: strings para
// enums (valores: none | readonly).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('team_dashboard_access', 20)->default('none')->after('has_seen_example');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('team_dashboard_access');
        });
    }
};
