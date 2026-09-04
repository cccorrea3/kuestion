<?php

namespace App\Console\Commands;

use App\Models\AnswerVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ola 1 P5/6 — Hallazgo 2: backfill de `found` en preguntas QBK pre-existentes.
 *
 * Las versiones creadas antes del deploy de P5/6 quedaron con `found = true`
 * (default de la migración) aunque su respuesta real era el fallback del motor
 * de consulta de QuBeKa ("No encontré información relevante..."). Eso rompe la
 * detección futura de la transición "sin respuesta → con respuesta"
 * (was_empty_prev se calcula leyendo el `found` de la versión actual).
 *
 * Por defecto el comando solo LISTA las versiones afectadas (dry-run). Para
 * aplicar el cambio hace falta --confirm. Nunca toca was_empty_prev ni
 * answer_text: solo corrige el flag `found`.
 */
class BackfillQuestionFound extends Command
{
    protected $signature = 'questions:backfill-found
        {--confirm : Aplica found=false a las versiones listadas (sin esta opción solo lista)}';

    protected $description = 'Corrige found=true en versiones QBK cuyo texto es el fallback "No encontré información relevante" (pre-deploy P5/6)';

    public function handle(): int
    {
        $versions = $this->affectedVersions();

        if ($versions->isEmpty()) {
            $this->info('No hay versiones QBK afectadas por el fallback de "no encontré información relevante".');

            return self::SUCCESS;
        }

        $this->table(
            ['versión', 'question', 'v#', 'actual', 'excerpt'],
            $versions->map(fn (AnswerVersion $v) => [
                'versión' => $v->id,
                'question' => str($v->question?->question_text)->limit(45),
                'v#' => $v->version_number,
                'actual' => $v->is_current ? 'sí' : 'no',
                'excerpt' => str($v->answer_text)->limit(60),
            ]),
        );

        $this->newLine();
        $this->info(sprintf('Versiones identificadas: %d', $versions->count()));

        if (! $this->option('confirm')) {
            $this->warn('Dry-run: no se modificó nada. Ejecutá con --confirm para aplicar found=false.');

            return self::SUCCESS;
        }

        $updated = AnswerVersion::whereIn('id', $versions->pluck('id'))->update(['found' => false]);

        $this->info(sprintf('Backfill aplicado: %d versión(es) actualizada(s) a found=false.', $updated));

        return self::SUCCESS;
    }

    /**
     * Versiones afectadas: repos qbk, found=true y texto que coincide con el
     * fallback del motor de consulta de QuBeKa. El filtro de texto se hace en
     * PHP (no LIKE) para que el criterio sea idéntico en MySQL y SQLite.
     *
     * @return Collection<int, AnswerVersion>
     */
    public function affectedVersions(): Collection
    {
        return AnswerVersion::query()
            ->with('question.repository')
            ->where('found', true)
            ->get()
            ->filter(fn (AnswerVersion $v) => $v->question?->repository?->connector_type === 'qbk'
                && static::matchesNoAnswerPattern($v->answer_text));
    }

    /**
     * True si el texto es (una variante de) el fallback del motor de consulta
     * cuando no hay resultados. Comparación case-insensitive sobre el substring
     * común que comparten las variantes vistas en producción y los mocks.
     */
    public static function matchesNoAnswerPattern(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return str_contains($normalized, 'no encontré información relevante');
    }
}
