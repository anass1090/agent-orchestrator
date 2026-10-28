<?php

use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\Exceptions\PhaseFailed;
use App\Pipeline\Worktree;
use Illuminate\Support\Facades\Http;

it('does nothing for a project not sourced from github', function () {
    $project = Project::factory()->create(['github_full_name' => null]);
    $run = Run::factory()->create(['project_id' => $project->id]);

    app(Worktree::class)->ensureGithubAccessIsValid($run);
})->throwsNoExceptions();

it('fails fast when no github account is connected', function () {
    $project = Project::factory()->create(['github_full_name' => 'octocat/demo']);
    $run = Run::factory()->create(['project_id' => $project->id]);

    app(Worktree::class)->ensureGithubAccessIsValid($run);
})->throws(PhaseFailed::class, 'GitHub is niet verbonden.');

it('passes when the stored token is still accepted by github', function () {
    GithubConnection::create(['access_token' => 'gho_faketoken', 'username' => 'octocat']);
    Http::fake(['api.github.com/user' => Http::response(['login' => 'octocat'])]);

    $project = Project::factory()->create(['github_full_name' => 'octocat/demo']);
    $run = Run::factory()->create(['project_id' => $project->id]);

    app(Worktree::class)->ensureGithubAccessIsValid($run);
})->throwsNoExceptions();

it('fails fast and disconnects when the stored token was revoked on github\'s side', function () {
    GithubConnection::create(['access_token' => 'gho_faketoken', 'username' => 'octocat']);
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    $project = Project::factory()->create(['github_full_name' => 'octocat/demo']);
    $run = Run::factory()->create(['project_id' => $project->id]);

    try {
        app(Worktree::class)->ensureGithubAccessIsValid($run);
    } catch (PhaseFailed $e) {
        expect($e->stopReason)->toBe('github_token_invalid');
    }

    expect(GithubConnection::summary())->toBeNull();
});
