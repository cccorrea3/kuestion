<?php

use App\Livewire\CreateQuestion;
use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (\App\Models\Question::where('user_id', config('app.user_id'))->exists()) {
        return redirect()->route('questions.index');
    }
    return view('welcome');
});

Route::get('/questions', QuestionFeed::class)->name('questions.index');
Route::get('/questions/create', CreateQuestion::class)->name('questions.create');
Route::get('/questions/{question}', QuestionDetail::class)->name('questions.show');
