<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\CreateQuestion;
use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
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
});

Route::middleware('auth')->group(function () {
    Route::get('/questions', QuestionFeed::class)->name('questions.index');
    Route::get('/questions/create', CreateQuestion::class)->name('questions.create');
    Route::get('/questions/{question}', QuestionDetail::class)->name('questions.show');
    Route::get('/tags', TagIndex::class)->name('tags.index');
    Route::get('/onboarding', fn () => view('auth.onboarding'))->name('onboarding');
    Route::post('/logout', function () {
        auth()->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect('/');
    })->name('logout');
});
