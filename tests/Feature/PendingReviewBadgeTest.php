<?php

namespace Tests\Feature;

use App\Livewire\PendingReviewBadge;
use App\Models\ContributionDraft;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PendingReviewBadgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->repository = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'connector_type' => 'qbk',
        ]);
    }

    public function test_badge_not_shown_when_no_pending_reviews(): void
    {
        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertDontSee('Pendientes')
            ->assertSet('count', 0);
    }

    public function test_badge_shows_correct_count(): void
    {
        ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Aporte 1',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 43,
            'texto' => 'Aporte 2',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertSee('Pendientes')
            ->assertSet('count', 2);
    }

    public function test_badge_excludes_reviewed_drafts(): void
    {
        ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Aporte revisado',
            'status' => ContributionDraft::STATUS_REVIEWED,
            'attempts' => 1,
        ]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertSet('count', 0);
    }

    public function test_badge_excludes_drafts_without_session_id(): void
    {
        ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => null,
            'texto' => 'Aporte sin sesión',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertSet('count', 0);
    }

    public function test_badge_links_to_latest_session(): void
    {
        $old = ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Aporte viejo',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
            'created_at' => now()->subDays(2),
        ]);

        $new = ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 99,
            'texto' => 'Aporte nuevo',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
            'created_at' => now()->addSeconds(5),
        ]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertSet('latestSessionId', fn ($val) => in_array($val, [42, 99]))
            ->assertSee(route('contributions.review', ['sessionId' => 99]));
    }

    public function test_badge_only_shows_for_current_user(): void
    {
        $otherUser = User::factory()->create();

        ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Aporte del usuario actual',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        Livewire::actingAs($otherUser)
            ->test(PendingReviewBadge::class)
            ->assertSet('count', 0);
    }

    public function test_badge_excludes_failed_drafts(): void
    {
        ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Aporte fallido',
            'status' => ContributionDraft::STATUS_FAILED,
            'attempts' => 2,
            'last_error' => 'Error',
        ]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertSet('count', 0);
    }

    public function test_badge_excludes_pending_retry_drafts(): void
    {
        ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Aporte pendiente de reintento',
            'status' => ContributionDraft::STATUS_PENDING,
            'attempts' => 1,
        ]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertSet('count', 0);
    }

    public function test_review_approved_clears_from_badge(): void
    {
        $draft = ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Aporte pendiente',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->assertSet('count', 1);

        // Simulate approve updating the draft
        $draft->update(['status' => ContributionDraft::STATUS_REVIEWED]);

        Livewire::actingAs($this->user)
            ->test(PendingReviewBadge::class)
            ->call('refreshCount')
            ->assertSet('count', 0);
    }
}
