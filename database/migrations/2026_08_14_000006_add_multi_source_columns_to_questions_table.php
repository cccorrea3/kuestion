<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 10.1 — Contrato mínimo multi-fuente: columnas que el código actual ignora
// (no se agregan a fillable/casts de Question). source_platform como string
// con default, consistente con status/review_frequency (no enum nativo).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('source_platform', 20)->default('kuaforia')->after('user_id');
            $table->string('external_id', 64)->nullable()->after('source_platform');
            $table->timestamp('last_external_check')->nullable()->after('external_id');

            $table->index(['user_id', 'source_platform']);
            $table->index(['external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source_platform']);
            $table->dropIndex(['external_id']);
            $table->dropColumn(['source_platform', 'external_id', 'last_external_check']);
        });
    }
};
