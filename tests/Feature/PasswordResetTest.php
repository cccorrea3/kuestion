<?php

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
    }

    public function test_user_can_request_reset_link(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'juan@example.com')
            ->call('sendResetLink')
            ->assertSet('status', 'Si el email existe, te enviamos un enlace para restablecer tu contraseña.');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_link_with_valid_token_resets_password(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);

        // Generamos un token real del broker (el mismo que llega por correo; en BD solo
        // queda su hash bcrypt). El canal mail de Laravel envía el MailMessage del reset
        // como vista (no Mailable), por lo que Mail::fake no lo captura; el token plano
        // se obtiene directo del repository.
        $token = Password::broker()->getRepository()->create($user);
        $this->assertNotNull($token);

        // El componente llama Auth::login() + session()->regenerate(); Livewire test no setea
        // el session store sobre el request del contenedor, así que lo adjuntamos manualmente.
        $this->app['request']->setLaravelSession($this->app['session']->driver());

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'nueva-password-123')
            ->set('password_confirmation', 'nueva-password-123')
            ->call('resetPassword')
            ->assertRedirect(route('questions.index'));

        $this->assertTrue(Hash::check('nueva-password-123', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_link_with_invalid_token_fails(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);
        $oldPassword = $user->password;

        Livewire::test(ResetPassword::class, ['token' => 'token-invalido'])
            ->set('email', $user->email)
            ->set('password', 'nueva-password-123')
            ->set('password_confirmation', 'nueva-password-123')
            ->call('resetPassword')
            ->assertSet('error', 'El enlace es inválido o expiró. Solicitá uno nuevo.');

        $this->assertSame($oldPassword, $user->fresh()->password);
    }

    public function test_reset_link_with_expired_token_fails(): void
    {
        $user = User::factory()->create(['email' => 'juan@example.com']);

        Password::broker()->sendResetLink(['email' => $user->email]);

        // Expiramos el token (lifetime 60 min → lo envejecemos 61).
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $token = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'nueva-password-123')
            ->set('password_confirmation', 'nueva-password-123')
            ->call('resetPassword')
            ->assertSet('error', 'El enlace es inválido o expiró. Solicitá uno nuevo.');

        $this->assertFalse(Hash::check('nueva-password-123', $user->fresh()->password));
    }

    public function test_reset_page_requires_guest(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('password.request'))
            ->assertRedirect('/');
    }
}
