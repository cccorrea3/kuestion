<?php

namespace Tests\Feature;

use App\Livewire\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => 'password']);
        $this->actingAs($this->user);
    }

    public function test_user_can_update_name_and_email(): void
    {
        Livewire::test(Settings::class)
            ->set('name', 'Nuevo Nombre')
            ->set('email', 'nuevo@example.com')
            ->call('updateProfile')
            ->assertSet('profileStatus', 'Datos actualizados.');

        $this->user->refresh();

        $this->assertSame('Nuevo Nombre', $this->user->name);
        $this->assertSame('nuevo@example.com', $this->user->email);
    }

    public function test_email_must_be_unique_excluding_self(): void
    {
        $other = User::factory()->create(['email' => 'otro@example.com']);

        Livewire::test(Settings::class)
            ->set('email', 'otro@example.com')
            ->call('updateProfile')
            ->assertHasErrors(['email' => 'unique']);

        // El propio email se puede conservar sin error.
        Livewire::test(Settings::class)
            ->set('email', $this->user->email)
            ->call('updateProfile')
            ->assertHasNoErrors();
    }

    public function test_user_can_change_password_with_current_password(): void
    {
        $component = Livewire::test(Settings::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'nueva-password-123')
            ->set('newPassword_confirmation', 'nueva-password-123')
            ->call('updatePassword')
            ->assertSet('passwordStatus', 'Contraseña actualizada.');

        $this->assertTrue(Hash::check('nueva-password-123', $this->user->fresh()->password));
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        Livewire::test(Settings::class)
            ->set('currentPassword', 'incorrecta')
            ->set('newPassword', 'nueva-password-123')
            ->set('newPassword_confirmation', 'nueva-password-123')
            ->call('updatePassword')
            ->assertSet('passwordError', 'La contraseña actual es incorrecta.');

        $this->assertFalse(Hash::check('nueva-password-123', $this->user->fresh()->password));
    }

    public function test_user_can_toggle_email_notifications(): void
    {
        // fresh(): el atributo por defecto se materializa al leer de BD (la factory
        // no setea email_notifications y el objeto en memoria no ve el default).
        $this->assertTrue($this->user->fresh()->email_notifications);

        Livewire::test(Settings::class)
            ->set('emailNotifications', false)
            ->call('toggleEmailNotifications');

        $this->assertFalse($this->user->fresh()->email_notifications);

        Livewire::test(Settings::class)
            ->set('emailNotifications', true)
            ->call('toggleEmailNotifications');

        $this->assertTrue($this->user->fresh()->email_notifications);
    }

    public function test_settings_requires_auth(): void
    {
        auth()->logout();

        $this->get(route('settings'))->assertRedirect(route('login'));
    }
}
