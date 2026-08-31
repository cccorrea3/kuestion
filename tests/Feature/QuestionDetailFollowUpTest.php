<?php

namespace Tests\Feature;

use App\Livewire\QuestionDetail;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Services\KuaforiaResponse;
use App\Services\QbkIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

class QuestionDetailFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_follow_up_uses_question_repository_tenant(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta del follow-up',
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

        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'resolved_tenant_slug' => 'qubeka',
            'connector_type' => '_test_fake',
        ]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->set('followUpQuestion', '¿Y qué más?')
            ->call('askFollowUp')
            ->assertSet('followUpAnswer', 'Respuesta del follow-up');

        $this->assertSame('qubeka', $fake->calls[0]['tenant_slug'] ?? null);
        // P9 — el follow-up NO actualiza last_used_at.
        $this->assertNull($repo->fresh()->last_used_at);
    }

    public function test_follow_up_blocks_when_repository_inactive(): void
    {
        $fake = new FakeRagProvider;

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

        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'invalid',
            'connector_type' => '_test_fake',
        ]);
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->set('followUpQuestion', '¿Y qué más?')
            ->call('askFollowUp')
            ->assertSet('followUpError', 'La conexión con tu fuente de conocimiento está inactiva. Actualizala en Configuración.');

        $this->assertCount(0, $fake->calls);
    }
}
