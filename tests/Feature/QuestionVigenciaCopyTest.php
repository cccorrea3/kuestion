<?php

namespace Tests\Feature;

use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuestionVigenciaCopyTest extends TestCase
{
    use RefreshDatabase;

    private function createQuestionWithRepo(string $connectorType, array $questionAttrs = []): array
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => $connectorType,
        ]);
        $question = Question::factory()->create(array_merge([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ], $questionAttrs));
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
        ]);

        return [$user, $question];
    }

    public function test_feed_shows_honest_copy_for_qbk_question(): void
    {
        [$user, $question] = $this->createQuestionWithRepo('qbk');

        $this->actingAs($user);

        Livewire::test(QuestionFeed::class)
            ->assertSee('sin reconfirmaciones registradas');
    }

    public function test_feed_keeps_current_copy_for_kuaforia_question(): void
    {
        [$user, $question] = $this->createQuestionWithRepo('kuaforia');

        $this->actingAs($user);

        Livewire::test(QuestionFeed::class)
            ->assertDontSee('sin reconfirmaciones registradas');
    }

    public function test_detail_shows_honest_copy_for_qbk_question(): void
    {
        [$user, $question] = $this->createQuestionWithRepo('qbk');

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertSee('sin reconfirmaciones registradas');
    }

    public function test_detail_keeps_current_copy_for_kuaforia_question(): void
    {
        [$user, $question] = $this->createQuestionWithRepo('kuaforia');

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertDontSee('sin reconfirmaciones registradas');
    }

    // F1.5 — fallo visible: un repo invalid no rompe el layout de la card ni el copy honesto.
    public function test_feed_renders_with_invalid_qbk_repo_without_breaking(): void
    {
        [$user, $question] = $this->createQuestionWithRepo('qbk');
        $question->repository->update(['status' => 'invalid']);

        $this->actingAs($user);

        Livewire::test(QuestionFeed::class)
            ->assertSee('sin reconfirmaciones registradas')
            ->assertSee('Conexión inactiva');
    }
}
