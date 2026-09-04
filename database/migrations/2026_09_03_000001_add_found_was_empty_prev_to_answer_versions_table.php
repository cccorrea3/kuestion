<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ola 1 P5/6 — F3 (3.1): persistir found y was_empty_prev en cada versión para
// detectar la transición "sin respuesta → con respuesta" (copy especial).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answer_versions', function (Blueprint $table) {
            $table->boolean('found')->default(true)->after('response_hash');
            $table->boolean('was_empty_prev')->default(false)->after('found');
        });
    }

    public function down(): void
    {
        Schema::table('answer_versions', function (Blueprint $table) {
            $table->dropColumn(['found', 'was_empty_prev']);
        });
    }
};