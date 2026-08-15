<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D2 — Sistema de Conectores RAG: questions.repository_id pasa a NOT NULL.
// En A2 quedó nullable (desviación documentada) porque los flujos de creación aún no lo
// seteaban; con D2, CreateQuestion y QuestionController::store lo resuelven siempre desde
// el repositorio activo del usuario, así que la columna pasa a ser obligatoria.
//
// MySQL exige dropear la FK para MODIFY la columna (error 1832): se dropea, se cambia
// la nulabilidad y se vuelve a crear la FK restrict.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['repository_id']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->uuid('repository_id')->nullable(false)->change();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->foreign('repository_id')->references('id')->on('repositories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['repository_id']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->uuid('repository_id')->nullable()->change();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->foreign('repository_id')->references('id')->on('repositories')->restrictOnDelete();
        });
    }
};
