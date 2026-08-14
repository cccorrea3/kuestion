<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 11.3 — Flag de onboarding: "Omitir" persiste por usuario para que el ejemplo
// no reaparezca en cada sesión (resolución de revisión §6.1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_seen_example')->default(false)->after('email_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('has_seen_example');
        });
    }
};
