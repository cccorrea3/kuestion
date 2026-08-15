<?php

namespace Tests\Feature;

use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepositoryStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_feed_shows_badge_with_link_when_repository_invalid(): void
    {
        // F1 (UX §6.9): invalid → "Conexión inactiva" con enlace a /settings?highlight=.
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'invalid',
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        Livewire::test(QuestionFeed::class)
            ->assertSee('Conexión inactiva')
            ->assertSeeHtml('href="'.route('settings', ['highlight' => $repo->id]).'"');
    }

    public function test_feed_shows_badge_without_action_when_repository_revoked(): void
    {
        // F1 (UX §6.9): revoked → "Desconectado" sin enlace de reparación.
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'revoked',
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        Livewire::test(QuestionFeed::class)
            ->assertSee('Desconectado')
            ->assertDontSee('Conexión inactiva');
    }

    public function test_feed_does_not_show_badge_when_repository_active(): void
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        Livewire::test(QuestionFeed::class)
            ->assertDontSee('Conexión inactiva')
            ->assertDontSee('Desconectado');
    }

    public function test_detail_shows_badge_with_link_when_repository_invalid(): void
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'invalid',
        ]);
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Conexión inactiva')
            ->assertSeeHtml('href="'.route('settings', ['highlight' => $repo->id]).'"');
    }

    public function test_detail_does_not_show_badge_when_repository_active(): void
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
        ]);
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->assertDontSee('Conexión inactiva')
            ->assertDontSee('Desconectado');
    }
}
