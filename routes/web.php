<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\RunEventPayloadController;
use App\Http\Controllers\RunFileController;
use App\Http\Controllers\RunLiveController;
use App\Http\Controllers\RunPhaseController;
use App\Http\Controllers\RunStopController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/runs');

Route::get('/runs', [RunController::class, 'index'])->name('runs.index');
Route::post('/runs', [RunController::class, 'store'])->name('runs.store');
Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');
Route::get('/runs/{run}/live', RunLiveController::class)->name('runs.live');
Route::post('/runs/{run}/stop', RunStopController::class)->name('runs.stop');
Route::post('/runs/{run}/phases/{phase}', RunPhaseController::class)->name('runs.phase');
Route::get('/runs/{run}/events/{event:sequence}/payload', RunEventPayloadController::class)->name('runs.events.payload');
Route::get('/runs/{run}/files/{path}', RunFileController::class)->where('path', '.*')->name('runs.files');

Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
