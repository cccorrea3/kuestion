<?php

namespace Tests\Feature;

use App\Livewire\QuestionDetail;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuestionConnectorBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_shows_connector_display_name(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => 'kuaforia',
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

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Kuaforia');
    }

    public function test_detail_shows_qbk_connector_name(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'connector_type' => 'qbk',
        ]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 50,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertSee('QuBeKa');
    }

    public function test_detail_shows_confidence_tooltip_when_low(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create(['user_id' => $user->uuid]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 50,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Búsqueda basada en texto');
    }

    public function test_detail_hides_confidence_tooltip_when_high(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create(['user_id' => $user->uuid]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 85,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertDontSee('Búsqueda basada en texto');
    }
}
