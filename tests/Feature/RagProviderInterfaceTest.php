<?php

namespace Tests\Feature;

use App\Contracts\RagProviderInterface;
use App\Livewire\CreateQuestion;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Services\KuaforiaResponse;
use App\Services\KuaforiaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

class RagProviderInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_resolves_to_kuaforia_service(): void
    {
        $this->assertInstanceOf(KuaforiaService::class, app(RagProviderInterface::class));
    }

    public function test_consumer_uses_injected_fake_provider_without_network(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta del fake',
            confidence: 88.0,
            sources: ['fake-source'],
        ));

        $this->app->instance(RagProviderInterface::class, $fake);

        $user = User::factory()->create();
        $repo = Repository::factory()->create(['user_id' => $user->uuid]);

        $this->actingAs($user);

        Livewire::test(CreateQuestion::class)
            ->set('questionText', '¿Qué es el Bloque 7?')
            ->call('save')
            ->assertSet('status', 'saved');

        $question = Question::where('question_text', '¿Qué es el Bloque 7?')->first();

        $this->assertNotNull($question);
        $this->assertSame('Respuesta del fake', $question->answer_text);
        $this->assertSame('¿Qué es el Bloque 7?', $fake->calls[0]['question'] ?? null);
        $this->assertNull($fake->calls[0]['conversation_id']);
        // D2 — el tenant sale del repositorio activo seleccionado.
        $this->assertSame('ispend', $fake->calls[0]['tenant_slug'] ?? null);
        $this->assertSame($repo->id, $question->repository_id);
        $this->assertNotNull($repo->fresh()->last_used_at); // P9
        $this->assertEquals(88.0, $question->versions()->first()->confidence);
    }

    public function test_fake_provider_echoes_conversation_id(): void
    {
        $fake = new FakeRagProvider;

        $response = $fake->consult('pregunta', 'conv-123');

        $this->assertSame('conv-123', $response->conversationId);
        $this->assertSame('Respuesta del proveedor fake', $response->answerText);
    }
}
