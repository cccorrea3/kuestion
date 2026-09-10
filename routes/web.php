<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\UnsubscribeController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\ContributeAporte;
use App\Livewire\ContributionReview;
use App\Livewire\CreateQuestion;
use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
use App\Livewire\ReviewTray;
use App\Livewire\Settings;
use App\Livewire\TagIndex;
use App\Livewire\TeamDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('questions.index');
    }

    return view('welcome');
})->name('home');

// Ola 2, Punto 5 — A.4: baja de correos por link firmado (sin login, pie de los mails).
Route::get('/unsubscribe/{user}', UnsubscribeController::class)
    ->middleware('signed')
    ->name('unsubscribe');

// B.3 — destino del link "Configurar mis notificaciones" del pie (requiere login).
Route::get('/settings/notifications', fn () => redirect()->route('settings'))
    ->name('settings-subscribe');

Route::middleware('guest')->group(function () {
    Route::get('/register', Register::class)->name('register');
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Route::get('/settings', Settings::class)->name('settings');
    Route::get('/questions', QuestionFeed::class)->name('questions.index');
    Route::get('/questions/create', CreateQuestion::class)->name('questions.create');
    Route::get('/contribute', ContributeAporte::class)->name('contribute');
    Route::get('/reviews', ReviewTray::class)->name('reviews.index');
    Route::get('/contributions/{sessionId}/review', ContributionReview::class)->name('contributions.review');
    Route::get('/questions/{question}', QuestionDetail::class)->name('questions.show');
    Route::get('/tags', TagIndex::class)->name('tags.index');
    Route::get('/team', TeamDashboard::class)->name('team.index');
    Route::get('/onboarding', fn () => view('auth.onboarding'))->name('onboarding');
    Route::post('/logout', LogoutController::class)->name('logout');
});
