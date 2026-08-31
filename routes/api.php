<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\QuestionController;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('rid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

Route::middleware(['api.key', 'throttle:100,1'])->group(function () {
    Route::get('/questions', [QuestionController::class, 'index']);
    Route::get('/questions/suggest-relations', [QuestionController::class, 'suggestRelations'])->middleware('throttle:60,1');
    Route::post('/questions', [QuestionController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/questions/{id}', [QuestionController::class, 'show']);
    Route::patch('/questions/{id}', [QuestionController::class, 'update']);
    Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);

    Route::get('/questions/{id}/versions', [QuestionController::class, 'versions']);
    Route::get('/questions/{id}/diff', [QuestionController::class, 'diff'])->middleware('throttle:30,1');

    Route::post('/questions/{id}/accept-change', [QuestionController::class, 'acceptChange'])->middleware('throttle:10,1');
    Route::post('/questions/{id}/dismiss-change', [QuestionController::class, 'dismissChange'])->middleware('throttle:10,1');
    Route::post('/questions/{id}/feedback', [QuestionController::class, 'feedback'])->middleware('throttle:30,1');

    Route::post('/questions/{id}/relations', [QuestionController::class, 'storeRelation'])->middleware('throttle:30,1');
    Route::delete('/questions/{id}/relations/{rid}', [QuestionController::class, 'destroyRelation'])->middleware('throttle:30,1');
    Route::get('/questions/{id}/backlinks', [QuestionController::class, 'backlinks']);
    Route::get('/tags', [QuestionController::class, 'tags']);
});

Route::middleware('api.key')->get('/health', HealthController::class);
