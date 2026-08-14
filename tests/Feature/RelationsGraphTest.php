<?php

namespace Tests\Feature;

use App\Livewire\QuestionDetail;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

class RelationsGraphTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function renderGraph(Question $question): string
    {
        return View::make('components.relations-graph', ['question' => $question])->render();
    }

    public function test_graph_renders_center_plus_neighbors_with_edge_labels(): void
    {
        $center = Question::factory()->create(['user_id' => $this->user->uuid, 'question_text' => '¿Qué es RAG?']);
        $a = Question::factory()->create(['user_id' => $this->user->uuid, 'question_text' => '¿Qué es un embedding?']);
        $b = Question::factory()->create(['user_id' => $this->user->uuid, 'question_text' => '¿Qué es un vector?']);

        // 1 saliente (center → a) + 1 entrante (b → center): 2 relaciones, 3 nodos.
        $center->outboundRelations()->create(['target_question_id' => $a->id, 'label' => 'relacionado con', 'relation_type' => 'manual']);
        $b->outboundRelations()->create(['target_question_id' => $center->id, 'label' => 'depende de', 'relation_type' => 'manual']);

        $html = $this->renderGraph($center);

        // N+1 nodos (2 relaciones a vecinos distintos → 3 círculos) y aristas con label.
        $this->assertSame(3, substr_count($html, '<circle'));
        $this->assertSame(2, substr_count($html, '<line'));
        $this->assertStringContainsString('relacionado con', $html);
        $this->assertStringContainsString('depende de', $html);

        // Nodos clicables que navegan al detalle.
        $this->assertStringContainsString(route('questions.show', $a->id), $html);
        $this->assertStringContainsString(route('questions.show', $b->id), $html);
        $this->assertStringContainsString('Red de relaciones', $html);
    }

    public function test_graph_deduplicates_shared_neighbor(): void
    {
        $center = Question::factory()->create(['user_id' => $this->user->uuid]);
        $neighbor = Question::factory()->create(['user_id' => $this->user->uuid]);

        // Saliente y entrante al MISMO vecino: 2 aristas pero 2 nodos (no 3).
        $center->outboundRelations()->create(['target_question_id' => $neighbor->id, 'label' => 'relacionado con', 'relation_type' => 'manual']);
        $neighbor->outboundRelations()->create(['target_question_id' => $center->id, 'label' => 'relacionado con', 'relation_type' => 'manual']);

        $html = $this->renderGraph($center);

        $this->assertSame(2, substr_count($html, '<circle'));
        $this->assertSame(2, substr_count($html, '<line'));
    }

    public function test_graph_is_hidden_without_relations(): void
    {
        $center = Question::factory()->create(['user_id' => $this->user->uuid]);

        $html = $this->renderGraph($center);

        $this->assertStringNotContainsString('Red de relaciones', $html);
    }

    public function test_flag_controls_render_in_question_detail(): void
    {
        $center = Question::factory()->create(['user_id' => $this->user->uuid, 'question_text' => '¿Qué es RAG?']);
        $neighbor = Question::factory()->create(['user_id' => $this->user->uuid]);
        $center->outboundRelations()->create(['target_question_id' => $neighbor->id, 'label' => 'relacionado con', 'relation_type' => 'manual']);

        $center->versions()->create([
            'version_number' => 1,
            'answer_text' => 'RAG es recuperación aumentada por generación.',
            'confidence' => 90,
            'sources' => [],
            'response_hash' => hash('sha256', 'RAG es recuperación aumentada por generación.'),
            'is_current' => true,
        ]);

        // Flag off (default): el grafo no se renderiza.
        $this->assertFalse(config('kuestion.features.relations_graph'));
        Livewire::test(QuestionDetail::class, ['question' => $center])
            ->assertDontSee('Red de relaciones');

        // Flag on: el grafo aparece en el detalle.
        config(['kuestion.features.relations_graph' => true]);
        Livewire::test(QuestionDetail::class, ['question' => $center])
            ->assertSee('Red de relaciones');
    }
}
