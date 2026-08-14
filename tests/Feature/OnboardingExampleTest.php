<?php

namespace Tests\Feature;

use App\Livewire\OnboardingExample;
use App\Livewire\QuestionFeed;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingExampleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_empty_feed_renders_onboarding_example(): void
    {
        // Sin preguntas: el empty state incluye el ejemplo.
        Livewire::test(QuestionFeed::class)
            ->assertSee('Todavía no tienes preguntas vigiladas')
            ->assertSee('Así funciona Kuestion')
            ->assertSee('Simular cambio');
    }

    public function test_feed_hides_example_when_user_has_seen_it(): void
    {
        $this->user->update(['has_seen_example' => true]);

        Livewire::test(QuestionFeed::class)
            ->assertSee('Todavía no tienes preguntas vigiladas')
            ->assertDontSee('Así funciona Kuestion');
    }

    public function test_simulate_change_reveals_the_diff(): void
    {
        Livewire::test(OnboardingExample::class)
            ->assertSet('status', 'idle')
            ->call('simulateChange')
            ->assertSet('status', 'diff')
            // El diff muestra la línea añadida (v2) y el cambio (10 → 5 días).
            ->assertSee('Los reembolsos de membresías anuales se procesan de forma prioritaria.')
            ->assertSee('Aceptar cambio')
            ->assertSee('Descartar cambio');
    }

    public function test_accept_and_dismiss_only_change_local_state(): void
    {
        Livewire::test(OnboardingExample::class)
            ->call('simulateChange')
            ->call('acceptChange')
            ->assertSet('status', 'accepted')
            ->assertSee('¡Así se ve un cambio aceptado!');

        Livewire::test(OnboardingExample::class)
            ->call('simulateChange')
            ->call('dismissChange')
            ->assertSet('status', 'dismissed')
            ->assertSee('Cambio descartado');
    }

    public function test_example_does_not_persist_to_database(): void
    {
        Livewire::test(OnboardingExample::class)
            ->call('simulateChange')
            ->call('acceptChange');

        Livewire::test(OnboardingExample::class)
            ->call('simulateChange')
            ->call('dismissChange');

        // El ejemplo es hardcodeado: ninguna tabla de datos cambia (el flag de omitir
        // se toca en el test de skip, no acá).
        $this->assertSame(0, Question::count());
        $this->assertSame(0, DB::table('answer_versions')->count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertFalse($this->user->fresh()->has_seen_example);
    }

    public function test_skip_persists_flag_and_hides_example(): void
    {
        Livewire::test(OnboardingExample::class)
            ->call('skip')
            ->assertSet('hidden', true);

        $this->assertTrue($this->user->fresh()->has_seen_example);

        // El feed ya no renderiza el ejemplo para este usuario.
        Livewire::test(QuestionFeed::class)->assertDontSee('Así funciona Kuestion');
    }
}
