<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Fix QA (Bloque 9): dropIndex(['uuid']) generaba users_uuid_index, pero el
            // índice creado por unique() es users_uuid_unique — el rollback fallaba con
            // "Can't DROP 'users_uuid_index'". Solo afecta el down (los entornos ya
            // aplicaron el up; los downs corren únicamente en rollback/fresh).
            $table->dropUnique('users_uuid_unique');
            $table->dropColumn('uuid');
        });
    }
};
