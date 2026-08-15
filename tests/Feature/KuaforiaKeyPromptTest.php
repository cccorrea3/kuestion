<?php

namespace Tests\Feature;

use App\Livewire\KuaforiaKeyPrompt;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KuaforiaKeyPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_is_visible_when_user_has_no_repositories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(KuaforiaKeyPrompt::class)
            ->assertSet('visible', true);
    }

    public function test_prompt_is_hidden_when_user_has_repositories(): void
    {
        $user = User::factory()->create();
        Repository::factory()->create(['user_id' => $user->uuid]);

        $this->actingAs($user);

        Livewire::test(KuaforiaKeyPrompt::class)
            ->assertSet('visible', false);
    }

    public function test_prompt_stays_hidden_after_dismiss(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(KuaforiaKeyPrompt::class)
            ->call('dismiss')
            ->assertSet('visible', false);

        Livewire::test(KuaforiaKeyPrompt::class)
            ->assertSet('visible', false);
    }
}
