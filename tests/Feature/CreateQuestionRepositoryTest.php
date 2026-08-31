<?php

namespace Tests\Feature;

use App\Livewire\CreateQuestion;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Services\KuaforiaResponse;
use App\Services\QbkIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

class CreateQuestionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(): FakeRagProvider
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta del proveedor',
            confidence: 90.0,
            sources: [],
        ));

        config(['kuestion.connectors._test_fake' => [
            'display_name' => 'Fake',
            'description' => '',
            'auth_fields' => [],
            'help_url' => null,
            'identity_resolver' => QbkIdentityResolver::class,
            'rag_provider' => get_class($fake),
            'signal_provider' => null,
        ]]);
        $this->app->instance(get_class($fake), $fake);

        return $fake;
    }

    public function test_saves_question_with_default_repository_when_single_active(): void
    {
        $fake = $this->fakeProvider();

        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => true,
            'connector_type' => '_test_fake',
        ]);

        $this->actingAs($user);

        Livewire::test(CreateQuestion::class)
            ->set('questionText', '¿Qué es RAG?')
            ->call('save')
            ->assertSet('status', 'saved');

        $question = Question::first();

        $this->assertSame($repo->id, $question->repository_id);
        $this->assertSame('ispend', $fake->calls[0]['tenant_slug'] ?? null);
        $this->assertNotNull($repo->fresh()->last_used_at); // P9
    }

    public function test_selector_preselects_default_and_validates_selection(): void
    {
        $fake = $this->fakeProvider();

        $user = User::factory()->create();
        $default = Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => true,
            'resolved_tenant_slug' => 'ispend',
            'connector_type' => '_test_fake',
        ]);
        $second = Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => false,
            'resolved_tenant_slug' => 'qubeka',
            'connector_type' => '_test_fake',
        ]);

        $this->actingAs($user);

        // is_default preseleccionado.
        Livewire::test(CreateQuestion::class)
            ->assertSet('repositoryId', $default->id);

        // Elegir el segundo repositorio → la pregunta usa su tenant.
        Livewire::test(CreateQuestion::class)
            ->set('repositoryId', $second->id)
            ->set('questionText', '¿Qué es RAG?')
            ->call('save')
            ->assertSet('status', 'saved');

        $question = Question::first();

        $this->assertSame($second->id, $question->repository_id);
        $this->assertSame('qubeka', $fake->calls[0]['tenant_slug'] ?? null);
    }

    public function test_selector_only_offers_active_repositories(): void
    {
        $this->fakeProvider();

        $user = User::factory()->create();
        Repository::factory()->create(['user_id' => $user->uuid, 'status' => 'active', 'is_default' => true]);
        Repository::factory()->create(['user_id' => $user->uuid, 'status' => 'invalid']);
        Repository::factory()->create(['user_id' => $user->uuid, 'status' => 'revoked']);

        $this->actingAs($user);

        $test = Livewire::test(CreateQuestion::class);

        // P11 — solo active entra al selector (acá: 1 de 3 repos).
        $repos = $test->instance()->repositories;

        $this->assertCount(1, $repos);
        $this->assertSame('active', $repos->first()->status);
    }

    public function test_blocks_without_active_repositories(): void
    {
        $this->fakeProvider();

        $user = User::factory()->create();
        Repository::factory()->create(['user_id' => $user->uuid, 'status' => 'revoked']);

        $this->actingAs($user);

        Livewire::test(CreateQuestion::class)
            ->assertSet('repositoryId', null)
            ->set('questionText', '¿Qué es RAG?')
            ->call('save')
            ->assertSet('status', 'error')
            ->assertSet('error', 'Conectá tu fuente de conocimiento en Configuración para crear preguntas.');

        $this->assertSame(0, Question::count());
    }
}
