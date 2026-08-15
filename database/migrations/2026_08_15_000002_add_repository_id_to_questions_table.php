<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A2 — Sistema de Conectores RAG: questions.repository_id.
// FK restrict (decisión cerrada §9.3): un repositorio con historial de preguntas no se borra.
// Backfill defensivo (P4, confirmado): entorno sin datos reales; las preguntas huérfanas se
// asignan al repositorio por defecto de su usuario (o el más antiguo si no hay default).
//
// DESVIACIÓN del plan v2.0 (documentada en la sección Fase A): la columna queda NULLABLE.
// El NOT NULL se aplica en la Fase D, cuando los flujos de creación de preguntas
// (CreateQuestion / QuestionController::store) pasen a setear repository_id — forzarlo
// ahora rompería la creación de preguntas entre fases (el propio plan dice que el NOT NULL
// "en la práctica" ocurre en D2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->uuid('repository_id')->nullable()->after('user_id');
            $table->foreign('repository_id')->references('id')->on('repositories')->restrictOnDelete();
        });

        $this->backfillOrphanQuestions();
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['repository_id']);
            $table->dropColumn('repository_id');
        });
    }

    private function backfillOrphanQuestions(): void
    {
        $orphans = DB::table('questions')->select('id', 'user_id')->whereNull('repository_id')->get();

        if ($orphans->isEmpty()) {
            return;
        }

        // Repositorio por defecto (is_default) por usuario; si no hay, el más antiguo.
        $reposByUser = DB::table('repositories')
            ->select('id', 'user_id')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get()
            ->groupBy('user_id');

        foreach ($orphans as $question) {
            $repo = $reposByUser->get($question->user_id)?->first();

            if ($repo === null) {
                // Usuario sin repositorio: se deja null (la columna es nullable en esta
                // fase). En el entorno verificado (P4) este caso no ocurre.
                continue;
            }

            DB::table('questions')->where('id', $question->id)->update(['repository_id' => $repo->id]);
        }
    }
};
