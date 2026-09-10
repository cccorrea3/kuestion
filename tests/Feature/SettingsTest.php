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

    // Ola 2, Punto 5 — A.3 (F2): el toggle booleano fue reemplazado por 3 niveles.
    public function test_user_can_update_email_preference_to_each_level(): void
    {
        // Default de la migración/factory.
        $this->assertSame(User::EMAIL_PREF_ALL, $this->user->fresh()->email_notifications);

        Livewire::test(Settings::class)
            ->set('emailNotifications', User::EMAIL_PREF_CRITICAL_ONLY)
            ->call('updateEmailPreference');
        $this->assertSame(User::EMAIL_PREF_CRITICAL_ONLY, $this->user->fresh()->email_notifications);

        Livewire::test(Settings::class)
            ->set('emailNotifications', User::EMAIL_PREF_NONE)
            ->call('updateEmailPreference');
        $this->assertSame(User::EMAIL_PREF_NONE, $this->user->fresh()->email_notifications);

        Livewire::test(Settings::class)
            ->set('emailNotifications', User::EMAIL_PREF_ALL)
            ->call('updateEmailPreference');
        $this->assertSame(User::EMAIL_PREF_ALL, $this->user->fresh()->email_notifications);
    }

    public function test_email_preference_rejects_unknown_value(): void
    {
        Livewire::test(Settings::class)
            ->set('emailNotifications', 'todo')
            ->call('updateEmailPreference');

        // Valor inválido no se persiste (mantiene el default).
        $this->assertSame(User::EMAIL_PREF_ALL, $this->user->fresh()->email_notifications);
    }

    public function test_settings_requires_auth(): void
    {
        auth()->logout();

        $this->get(route('settings'))->assertRedirect(route('login'));
    }
}
