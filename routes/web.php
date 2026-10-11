<?php

use App\Http\Controllers\ClaudeLoginCodeController;
use App\Http\Controllers\ClaudeLoginController;
use App\Http\Controllers\ClaudeLogoutController;
use App\Http\Controllers\ClaudeStatusController;
use App\Http\Controllers\ConnectionsController;
use App\Http\Controllers\GithubCallbackController;
use App\Http\Controllers\GithubConnectController;
use App\Http\Controllers\GithubCredentialsController;
use App\Http\Controllers\GithubDisconnectController;
use App\Http\Controllers\GithubRepositoriesController;
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
Route::delete('/runs/{run}', [RunController::class, 'destroy'])->name('runs.destroy');

Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::post('/projects/from-github', [ProjectController::class, 'storeFromGithub'])->name('projects.store-from-github');
Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

Route::get('/claude/status', ClaudeStatusController::class)->name('claude.status');
Route::post('/claude/login', ClaudeLoginController::class)->name('claude.login');
Route::post('/claude/login/code', ClaudeLoginCodeController::class)->name('claude.login.code');
Route::post('/claude/logout', ClaudeLogoutController::class)->name('claude.logout');

Route::get('/connections', [ConnectionsController::class, 'index'])->name('connections.index');

Route::post('/github/credentials', GithubCredentialsController::class)->name('github.credentials');
Route::get('/github/connect', GithubConnectController::class)->name('github.connect');
Route::get('/github/callback', GithubCallbackController::class)->name('github.callback');
Route::post('/github/disconnect', GithubDisconnectController::class)->name('github.disconnect');
Route::get('/github/repositories', GithubRepositoriesController::class)->name('github.repositories');
