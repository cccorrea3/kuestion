<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Jobs\CleanupContributionDraftsJob;
use App\Livewire\ContributeAporte;
use App\Models\ContributionDraft;
use App\Models\Repository;
use App\Models\User;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ContributionDraftTest extends TestCase
{
    use RefreshDatabase;

    private function fakeService(?array $result = null, ?\Throwable $exception = null): void
    {
        $fake = \Mockery::mock(QbkContributionService::class);

        if ($exception) {
            $fake->shouldReceive('contribute')->andThrow($exception);
        } else {
            $fake->shouldReceive('contribute')->andReturn($result ?? [
                'session_id' => 42,
                'status' => 'pendiente_revision',
                'resumen' => 'OK',
            ]);
        }

        $this->app->instance(QbkContributionService::class, $fake);
    }

    private function createUserWithRepo(): User
    {
        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|test_token'],
        ]);

        return $user;
    }

    // --- Model tests ---

    public function test_draft_created_on_service_failure(): void
    {
        $this->fakeService(exception: new KuaforiaException('Token inválido', 401));

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSet('status', 'error')
            ->assertSet('hasDraft', true);

        $draft = ContributionDraft::where('user_id', $user->uuid)->first();
        $this->assertNotNull($draft);
        $this->assertSame('pending_retry', $draft->status);
        $this->assertSame('El batch del banco no llega antes de las 6am', $draft->texto);
        $this->assertSame(1, $draft->attempts);
        $this->assertSame('Token inválido', $draft->last_error);
    }

    public function test_draft_created_on_success_with_session_id(): void
    {
        $this->fakeService();

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSet('status', 'saved');

        // After a successful contribute, a draft is created to persist
        // qbk_session_id for the pending review indicator (Punto 4, Fase 3).
        $draft = ContributionDraft::where('user_id', $user->uuid)->first();
        $this->assertNotNull($draft);
        $this->assertEquals(ContributionDraft::STATUS_SENT, $draft->status);
        $this->assertNotNull($draft->qbk_session_id);
    }

    public function test_draft_marked_sent_on_success_after_retry(): void
    {
        // First call: failure → creates draft.
        $this->fakeService(exception: new KuaforiaException('Error', 500));

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSet('status', 'error')
            ->assertSet('hasDraft', true);

        $draft = ContributionDraft::where('user_id', $user->uuid)->first();
        $this->assertSame('pending_retry', $draft->status);

        // Second call: success → marks draft as sent.
        $this->fakeService();

        Livewire::test(ContributeAporte::class)
            ->call('retryFromDraft')
            ->assertSet('status', 'saved')
            ->assertSet('hasDraft', false);

        $draft->refresh();
        $this->assertSame('sent', $draft->status);
    }

    public function test_retry_increments_attempt_counter(): void
    {
        // First failure.
        $this->fakeService(exception: new KuaforiaException('Error 1', 500));

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco no llega antes de las 6am')
            ->call('submit');

        $draft = ContributionDraft::where('user_id', $user->uuid)->first();
        $this->assertSame(1, $draft->attempts);

        // Second failure (retry).
        $this->fakeService(exception: new KuaforiaException('Error 2', 500));

        Livewire::test(ContributeAporte::class)
            ->call('retryFromDraft');

        $draft->refresh();
        $this->assertSame(2, $draft->attempts);
        $this->assertSame('Error 2', $draft->last_error);
    }

    public function test_draft_stores_pregunta_previa(): void
    {
        $this->fakeService(exception: new KuaforiaException('Error', 500));

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco no llega antes de las 6am')
            ->set('preguntaPrevia', '¿Por qué falla el job?')
            ->call('submit');

        $draft = ContributionDraft::where('user_id', $user->uuid)->first();
        $this->assertSame('¿Por qué falla el job?', $draft->pregunta_previa);
    }

    public function test_pending_draft_loaded_on_mount(): void
    {
        $user = $this->createUserWithRepo();

        // Create a pending draft manually.
        ContributionDraft::create([
            'user_id' => $user->uuid,
            'repository_id' => null,
            'texto' => 'Texto pendiente de reintento',
            'pregunta_previa' => '¿Pregunta anterior?',
            'status' => 'pending_retry',
            'attempts' => 1,
            'last_error' => 'Error anterior',
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertSet('hasDraft', true)
            ->assertSet('texto', 'Texto pendiente de reintento')
            ->assertSet('preguntaPrevia', '¿Pregunta anterior?');
    }

    public function test_sent_draft_not_loaded_on_mount(): void
    {
        $user = $this->createUserWithRepo();

        ContributionDraft::create([
            'user_id' => $user->uuid,
            'repository_id' => null,
            'texto' => 'Texto enviado',
            'status' => 'sent',
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertSet('hasDraft', false)
            ->assertSet('texto', '');
    }

    public function test_error_state_shows_retry_button_when_draft_exists(): void
    {
        $this->fakeService(exception: new KuaforiaException('Error', 500));

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSee('Reintentar')
            ->assertSee('borrador');
    }

    public function test_error_state_shows_submit_button_when_no_draft(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'Texto válido para aportar aquí')
            // Don't submit — just check the idle state shows Aportar button.
            ->assertSee('Aportar')
            ->assertDontSee('Reintentar');
    }

    public function test_retry_does_nothing_without_draft(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->call('retryFromDraft')
            ->assertSet('status', 'idle');
    }

    // --- Cleanup job tests ---

    public function test_cleanup_deletes_old_drafts(): void
    {
        $user = $this->createUserWithRepo();

        // Create old pending draft (8 days ago).
        $old = ContributionDraft::create([
            'user_id' => $user->uuid,
            'texto' => 'Viejo borrador',
            'status' => 'pending_retry',
        ]);
        DB::table('contribution_drafts')->where('id', $old->id)->update(['created_at' => now()->subDays(8)]);

        // Create recent pending draft.
        $recent = ContributionDraft::create([
            'user_id' => $user->uuid,
            'texto' => 'Borrador reciente',
            'status' => 'pending_retry',
        ]);

        // Create old sent draft (should not be deleted).
        $oldSent = ContributionDraft::create([
            'user_id' => $user->uuid,
            'texto' => 'Enviado viejo',
            'status' => 'sent',
        ]);
        DB::table('contribution_drafts')->where('id', $oldSent->id)->update(['created_at' => now()->subDays(10)]);

        (new CleanupContributionDraftsJob)->handle();

        $this->assertDatabaseMissing('contribution_drafts', ['id' => $old->id]);
        $this->assertDatabaseHas('contribution_drafts', ['id' => $recent->id]);
        $this->assertDatabaseHas('contribution_drafts', ['id' => $oldSent->id]);
    }

    public function test_cleanup_deletes_old_failed_drafts(): void
    {
        $user = $this->createUserWithRepo();

        $oldFailed = ContributionDraft::create([
            'user_id' => $user->uuid,
            'texto' => 'Fallido viejo',
            'status' => 'failed',
        ]);
        DB::table('contribution_drafts')->where('id', $oldFailed->id)->update(['created_at' => now()->subDays(8)]);

        (new CleanupContributionDraftsJob)->handle();

        $this->assertDatabaseMissing('contribution_drafts', ['id' => $oldFailed->id]);
    }

    public function test_cleanup_keeps_recent_drafts(): void
    {
        $user = $this->createUserWithRepo();

        $recent = ContributionDraft::create([
            'user_id' => $user->uuid,
            'texto' => 'Reciente',
            'status' => 'pending_retry',
        ]);

        (new CleanupContributionDraftsJob)->handle();

        $this->assertDatabaseHas('contribution_drafts', ['id' => $recent->id]);
    }

    // --- Model scope tests ---

    public function test_scope_pending_filters_correctly(): void
    {
        $user = $this->createUserWithRepo();

        $pending = ContributionDraft::create([
            'user_id' => $user->uuid,
            'texto' => 'pending',
            'status' => 'pending_retry',
        ]);

        ContributionDraft::create([
            'user_id' => $user->uuid,
            'texto' => 'sent',
            'status' => 'sent',
        ]);

        $results = ContributionDraft::pending()->get();
        $this->assertCount(1, $results);
        $this->assertSame($pending->id, $results->first()->id);
    }

    public function test_scope_for_user_filters_correctly(): void
    {
        $user1 = $this->createUserWithRepo();
        $user2 = $this->createUserWithRepo();

        ContributionDraft::create([
            'user_id' => $user1->uuid,
            'texto' => 'user1 draft',
            'status' => 'pending_retry',
        ]);

        ContributionDraft::create([
            'user_id' => $user2->uuid,
            'texto' => 'user2 draft',
            'status' => 'pending_retry',
        ]);

        $results = ContributionDraft::forUser($user1->uuid)->get();
        $this->assertCount(1, $results);
        $this->assertSame('user1 draft', $results->first()->texto);
    }
}
