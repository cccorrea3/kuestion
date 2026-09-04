<?php

namespace Tests\Feature;

use App\Livewire\QuestionFeed;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuestionSourceTagTest extends TestCase
{
    use RefreshDatabase;

    private function createQuestionWithRepo(string $connectorType): array
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => $connectorType,
        ]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);
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

    // 2.3 — con un solo repo activo, el feed no muestra el tag de fuente.
    public function test_feed_hides_source_tag_with_single_active_repo(): void
    {
        [$user, $question] = $this->createQuestionWithRepo('qbk');

        $this->actingAs($user);

        Livewire::test(QuestionFeed::class)
            ->assertDontSee('QuBeKa');
    }

    // 2.3 — con dos repos activos, cada card muestra su fuente.
    public function test_feed_shows_source_tag_with_two_active_repos(): void
    {
        $user = User::factory()->create();
        $repoQbk = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => 'qbk',
            'status' => 'active',
        ]);
        $repoKua = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => 'kuaforia',
            'status' => 'active',
        ]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repoQbk->id,
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionFeed::class)
            ->assertSee('QuBeKa');
    }

    // 2.3 — un repo activo + uno no activo cuenta como uno solo (NB2 del plan).
    public function test_feed_hides_source_tag_when_second_repo_is_inactive(): void
    {
        $user = User::factory()->create();
        $repoQbk = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => 'qbk',
            'status' => 'active',
        ]);
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => 'kuaforia',
            'status' => 'invalid',
        ]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repoQbk->id,
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionFeed::class)
            ->assertDontSee('QuBeKa');
    }
}
