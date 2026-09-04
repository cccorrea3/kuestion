<?php

namespace Tests\Feature;

use App\Livewire\QuestionDetail;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 1 P5/6 — Hallazgo 1 (opción a): el copy especial de la transición
 * "sin respuesta → con respuesta" también se renderiza en el bloque de review
 * del detalle (la superficie in-app a la que navega el badge), en vez del
 * título genérico "Cambio detectado".
 */
class QuestionDetailReviewCopyTest extends TestCase
{
    use RefreshDatabase;

    private function userWithQbkQuestion(array $v2Overrides = []): array
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
            'has_unreviewed_changes' => true,
            'last_change_detected_at' => now(),
        ]);

        // v1 — sin respuesta (found=false, fallback del motor QBK).
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'No encontré información relevante en el grafo para responder esta pregunta',
            'confidence' => 0,
            'sources' => [],
            'response_hash' => hash('sha256', 'fallback'),
            'found' => false,
            'was_empty_prev' => false,
            'is_current' => false,
        ]);

        // v2 — la versión nueva bajo revisión.
        $question->versions()->create(array_merge([
            'version_number' => 2,
            'answer_text' => 'Ahora sí hay una respuesta real para esta pregunta',
            'confidence' => 85,
            'sources' => [['node_id' => 'NK-1', 'tipo' => 'N-K']],
            'response_hash' => hash('sha256', 'respuesta real'),
            'found' => true,
            'was_empty_prev' => true,
            'is_current' => true,
        ], $v2Overrides));

        return [$user, $question];
    }

    public function test_review_block_shows_special_copy_when_was_empty_prev(): void
    {
        [$user, $question] = $this->userWithQbkQuestion();

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Ahora hay información sobre algo que preguntaste')
            ->assertDontSee('Cambio detectado');
    }

    public function test_review_block_keeps_generic_copy_when_normal_change(): void
    {
        [$user, $question] = $this->userWithQbkQuestion([
            'was_empty_prev' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Cambio detectado')
            ->assertDontSee('Ahora hay información sobre algo que preguntaste');
    }
}
