<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillQuestionFound;
use App\Models\AnswerVersion;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Ola 1 P5/6 — Hallazgo 2: backfill de `found` en versiones QBK cuyo texto es
 * el fallback del motor de consulta ("No encontré información relevante").
 * Solo lista sin --confirm; con --confirm corrige found=false y no toca
 * was_empty_prev.
 */
class BackfillQuestionFoundTest extends TestCase
{
    use RefreshDatabase;

    private function qbkVersion(string $answerText, bool $found = true, bool $wasEmptyPrev = false): AnswerVersion
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => 'qbk',
            'resolved_tenant_slug' => 'qubeka',
        ]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);

        return $question->versions()->create([
            'version_number' => 1,
            'answer_text' => $answerText,
            'confidence' => 0,
            'sources' => [],
            'response_hash' => hash('sha256', $answerText),
            'found' => $found,
            'was_empty_prev' => $wasEmptyPrev,
            'is_current' => true,
        ]);
    }

    // Patrón — casos positivos: variantes reales del fallback de QBK.
    public function test_matches_no_answer_pattern_positive_cases(): void
    {
        $this->assertTrue(BackfillQuestionFound::matchesNoAnswerPattern('No encontré información relevante'));
        $this->assertTrue(BackfillQuestionFound::matchesNoAnswerPattern('no encontré información relevante para tu pregunta.'));
        $this->assertTrue(BackfillQuestionFound::matchesNoAnswerPattern('No encontré información relevante en la base de conocimiento.'));
        $this->assertTrue(BackfillQuestionFound::matchesNoAnswerPattern('  No encontré información relevante en el grafo de conocimiento para responder esta pregunta  '));
    }

    // Patrón — casos negativos: respuestas reales o textos que no son el fallback.
    public function test_matches_no_answer_pattern_negative_cases(): void
    {
        $this->assertFalse(BackfillQuestionFound::matchesNoAnswerPattern('Sherlock Holmes es el detective más famoso de la literatura'));
        $this->assertFalse(BackfillQuestionFound::matchesNoAnswerPattern('Cristian Correa es un ingeniero Civil informático'));
        $this->assertFalse(BackfillQuestionFound::matchesNoAnswerPattern('No encontré información sobre otro tema')); // sin "relevante"
        $this->assertFalse(BackfillQuestionFound::matchesNoAnswerPattern('No encontré datos relevantes para responder')); // sin "información"
        $this->assertFalse(BackfillQuestionFound::matchesNoAnswerPattern(''));
    }

    // Identificación — solo versiones qbk con found=true y texto de fallback.
    public function test_affected_versions_filters_connector_found_and_pattern(): void
    {
        // Afectada: qbk + found=true + texto fallback.
        $affected = $this->qbkVersion('No encontré información relevante en el grafo de conocimiento');

        // No afectada: found ya false (aunque el texto sea fallback).
        $this->qbkVersion('No encontré información relevante para tu pregunta', found: false);

        // No afectada: qbk pero respuesta real.
        $this->qbkVersion('Sherlock Holmes es un detective que vive en Baker Street');

        // No afectada: texto fallback pero connector kuaforia.
        $user = User::factory()->create();
        $kuaRepo = Repository::factory()->create(['user_id' => $user->uuid, 'connector_type' => 'kuaforia']);
        $kuaQuestion = Question::factory()->create(['user_id' => $user->uuid, 'repository_id' => $kuaRepo->id]);
        $kuaQuestion->versions()->create([
            'version_number' => 1,
            'answer_text' => 'No encontré información relevante en la base de conocimiento',
            'confidence' => 0,
            'sources' => [],
            'response_hash' => hash('sha256', 'x'),
            'found' => true,
            'was_empty_prev' => false,
            'is_current' => true,
        ]);

        $versions = (new BackfillQuestionFound)->affectedVersions();

        $this->assertCount(1, $versions);
        $this->assertSame($affected->id, $versions->first()->id);
    }

    // Dry-run: lista pero no modifica nada.
    public function test_dry_run_does_not_modify_versions(): void
    {
        $version = $this->qbkVersion('No encontré información relevante en el grafo de conocimiento');

        Artisan::call('questions:backfill-found');
        $output = Artisan::output();

        $this->assertStringContainsString('Versiones identificadas: 1', $output);
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertTrue($version->fresh()->found); // intacta
    }

    // Confirm: aplica found=false y no toca was_empty_prev.
    public function test_confirm_applies_found_false_without_touching_was_empty_prev(): void
    {
        $version = $this->qbkVersion('No encontré información relevante en el grafo de conocimiento', wasEmptyPrev: true);

        Artisan::call('questions:backfill-found', ['--confirm' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Backfill aplicado: 1', $output);

        $fresh = $version->fresh();
        $this->assertFalse($fresh->found);
        $this->assertTrue($fresh->was_empty_prev); // sin cambios
        $this->assertStringContainsString('No encontré información relevante', $fresh->answer_text); // texto intacto
    }
}
