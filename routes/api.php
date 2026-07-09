<?php

use App\Http\Controllers\QuestionController;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

Route::middleware(['api.key', 'throttle:100,1'])->group(function () {
    Route::get('/questions', [QuestionController::class, 'index']);
    Route::post('/questions', [QuestionController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/questions/{id}', [QuestionController::class, 'show']);
    Route::patch('/questions/{id}', [QuestionController::class, 'update']);
    Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);

    Route::get('/questions/{id}/versions', [QuestionController::class, 'versions']);
    Route::get('/questions/{id}/diff', [QuestionController::class, 'diff'])->middleware('throttle:30,1');
});
