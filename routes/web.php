<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\CreateQuestion;
use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
use App\Livewire\Settings;
use App\Livewire\TagIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('questions.index');
    }

    return view('welcome');
})->name('home');

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
    Route::get('/questions/{question}', QuestionDetail::class)->name('questions.show');
    Route::get('/tags', TagIndex::class)->name('tags.index');
    Route::get('/onboarding', fn () => view('auth.onboarding'))->name('onboarding');
    Route::post('/logout', LogoutController::class)->name('logout');
});
