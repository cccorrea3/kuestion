<?php

namespace App\Jobs;

use App\Models\Question;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

// ponytail: hard-deletes old versions of archived questions.
// Política (1.6): las preguntas activas conservan TODAS sus versiones; las archivadas
// solo las últimas N (config kuestion.retention.archived_versions, default 5).
class CleanupOldVersionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Question::onlyTrashed()->chunk(100, function ($questions) {
            foreach ($questions as $question) {
                $this->pruneArchivedVersions($question);
            }
        });
    }

    private function pruneArchivedVersions(Question $question): void
    {
        $keep = (int) config('kuestion.retention.archived_versions', 5);

        DB::transaction(function () use ($question, $keep) {
            // Lock de fila: evita condiciones de carrera con otros workers sobre la misma pregunta.
            // withTrashed(): el scope global de SoftDeletes excluye las archivadas en el re-fetch.
            $locked = Question::withTrashed()->whereKey($question->id)->lockForUpdate()->first();

            $keepIds = $locked->versions()
                ->orderByDesc('version_number')
                ->take($keep)
                ->pluck('id');

            $locked->versions()
                ->where('is_current', false)
                ->whereNotIn('id', $keepIds)
                ->delete();
        });
    }
}
