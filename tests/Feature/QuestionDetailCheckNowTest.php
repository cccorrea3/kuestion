<?php

namespace Tests\Feature;

use App\Contracts\RagProviderInterface;
use App\Livewire\QuestionDetail;
use App\Models\Question;
use App\Models\User;
use App\Services\KuaforiaResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

class QuestionDetailCheckNowTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_now_detects_change_and_shows_success(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta nueva tras modificar el caso',
            confidence: 90.0,
            sources: [],
        ));
        $this->app->instance(RagProviderInterface::class, $fake);

        $user = User::factory()->create();
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->call('checkNow')
            ->assertSet('checkResultType', 'success')
            ->assertSet('checkResult', 'Cambio detectado: se creó la versión 2 con la respuesta actualizada.')
            ->assertSet('showReview', true);

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame('Respuesta nueva tras modificar el caso', $question->fresh()->currentVersion->answer_text);
        $this->assertSame('ispend', $fake->calls[0]['tenant_slug'] ?? null);
        $this->assertCount(1, DB::table('notifications')->get());
    }

    public function test_check_now_unchanged_shows_info_message(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta original',
            confidence: 80.0,
            sources: [],
        ));
        $this->app->instance(RagProviderInterface::class, $fake);

        $user = User::factory()->create();
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->call('checkNow')
            ->assertSet('checkResultType', 'info')
            ->assertSet('checkResult', 'Sin cambios: la respuesta de Kuaforia es idéntica a la actual.');

        $this->assertSame(1, $question->versions()->count());
        $this->assertCount(0, DB::table('notifications')->get());
    }
}
