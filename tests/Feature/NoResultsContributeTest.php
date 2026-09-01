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

class NoResultsContributeTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(bool $found = true, string $answer = 'Respuesta de prueba'): FakeRagProvider
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: $answer,
            confidence: 90.0,
            sources: $found ? [['node_id' => 'NK-1', 'tipo' => 'N-K']] : [],
            found: $found,
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

    public function test_banner_shows_when_found_false(): void
    {
        $this->fakeProvider(found: false, answer: '');

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => true,
            'connector_type' => '_test_fake',
        ]);

        $this->actingAs($user);

        Livewire::test(CreateQuestion::class)
            ->set('questionText', '¿Qué es QUBKA?')
            ->call('save')
            ->assertSet('status', 'saved')
            ->assertSet('noResults', true)
            ->assertSee('No encontramos información sobre esto')
            ->assertSee('¿Querés aportar lo que sabés?')
            ->assertSee('Aportar conocimiento');
    }

    public function test_banner_does_not_show_when_found_true(): void
    {
        $this->fakeProvider(found: true, answer: 'QUBKA es una plataforma.');

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => true,
            'connector_type' => '_test_fake',
        ]);

        $this->actingAs($user);

        Livewire::test(CreateQuestion::class)
            ->set('questionText', '¿Qué es QUBKA?')
            ->call('save')
            ->assertSet('status', 'saved')
            ->assertSet('noResults', false)
            ->assertDontSee('No encontramos información sobre esto')
            ->assertDontSee('Aportar conocimiento');
    }

    public function test_banner_links_to_contribute_with_prev_param(): void
    {
        $this->fakeProvider(found: false);

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => true,
            'connector_type' => '_test_fake',
        ]);

        $this->actingAs($user);

        Livewire::test(CreateQuestion::class)
            ->set('questionText', '¿Qué es QUBKA?')
            ->call('save')
            ->assertSee(route('contribute', ['prev' => '¿Qué es QUBKA?']));
    }

    public function test_question_saved_even_when_not_found(): void
    {
        $this->fakeProvider(found: false, answer: '');

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'is_default' => true,
            'connector_type' => '_test_fake',
        ]);

        $this->actingAs($user);

        Livewire::test(CreateQuestion::class)
            ->set('questionText', '¿Qué es QUBKA?')
            ->call('save')
            ->assertSet('status', 'saved');

        // Question is still saved for vigilancia purposes.
        $this->assertSame(1, Question::count());
        $question = Question::first();
        $this->assertSame('¿Qué es QUBKA?', $question->question_text);
    }

    public function test_contribute_route_receives_prev_param(): void
    {
        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
        ]);

        $this->actingAs($user);

        $response = $this->get(route('contribute', ['prev' => '¿Qué es QUBKA?']));
        $response->assertStatus(200);
        $response->assertSee('¿Qué es QUBKA?');
        $response->assertSee('Tu pregunta anterior:');
    }

    public function test_kuaforia_response_found_default_is_true(): void
    {
        $response = new KuaforiaResponse(
            answerText: 'test',
            confidence: 1.0,
            sources: [],
        );

        $this->assertTrue($response->found);
    }

    public function test_kuaforia_response_found_can_be_false(): void
    {
        $response = new KuaforiaResponse(
            answerText: '',
            confidence: 0.0,
            sources: [],
            found: false,
        );

        $this->assertFalse($response->found);
    }
}
