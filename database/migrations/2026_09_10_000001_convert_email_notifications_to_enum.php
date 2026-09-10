<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ola 2, Punto 5 — Fase A.1: preferencia de correo en 3 niveles (spec §5).
// El booleano se convierte a string enum preservando datos: true→all, false→none.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_notifications', 20)->default('all')->change();
        });

        // Mapeo de datos existentes: booleano ('1'/'0') → enum. Orden importa:
        // '0' debe caer en 'none' antes del catch-all (null/otros → 'all', default).
        DB::table('users')->where('email_notifications', '1')->update(['email_notifications' => 'all']);
        DB::table('users')->where('email_notifications', '0')->update(['email_notifications' => 'none']);
        DB::table('users')->whereNotIn('email_notifications', ['all', 'critical_only', 'none'])
            ->update(['email_notifications' => 'all']);
    }

    public function down(): void
    {
        // Regreso a booleano: all/critical_only→true, none→false.
        DB::table('users')->where('email_notifications', 'none')->update(['email_notifications' => '']);
        DB::table('users')->whereIn('email_notifications', ['all', 'critical_only'])->update(['email_notifications' => '1']);

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true)->change();
        });
    }
};
