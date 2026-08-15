<?php

namespace Tests\Feature;

use App\Contracts\RagProviderInterface;
use App\Livewire\QuestionDetail;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Services\KuaforiaResponse;
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
        $this->app->instance(RagProviderInterface::class, $fake);

        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'resolved_tenant_slug' => 'qubeka',
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
        $this->app->instance(RagProviderInterface::class, $fake);

        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'invalid',
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
