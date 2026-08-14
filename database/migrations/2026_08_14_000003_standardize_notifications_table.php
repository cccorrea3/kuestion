<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 1.1.1 — Adapta la tabla notifications (custom: user_id) al esquema estándar de Laravel:
// notifiable_type + notifiable_id (morphs), con backfill desde user_id (uuid → id de users).
// El valor de `type` pasa a la clase (App\Notifications\...) y puede superar los 50 chars,
// por eso se amplía la columna a 255 (esquema estándar de Laravel).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('notifiable_type')->nullable()->after('type');
            $table->unsignedBigInteger('notifiable_id')->nullable()->index()->after('notifiable_type');
            $table->string('type', 255)->change();
            // El canal database de Laravel escribe created_at Y updated_at.
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        // Backfill: user_id guarda el uuid del usuario; notifiable_id debe guardar la PK (id).
        DB::table('notifications')->update([
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => DB::raw('(SELECT u.id FROM users u WHERE u.uuid = notifications.user_id)'),
        ]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->index()->after('id');
        });

        DB::table('notifications')->update([
            'user_id' => DB::raw('(SELECT u.uuid FROM users u WHERE u.id = notifications.notifiable_id)'),
        ]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_id']);
            $table->dropColumn(['notifiable_type', 'notifiable_id', 'updated_at']);
        });
    }
};
