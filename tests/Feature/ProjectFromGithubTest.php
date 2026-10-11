<?php

use App\Models\GithubConnection;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/github-clone-test-'.uniqid();
    config(['pipeline.github_projects_path' => $this->tmp]);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('refuses to register a project from github when not connected', function () {
    $this->post('/projects/from-github', [
        'full_name' => 'octocat/hello-world',
        'branch' => 'main',
    ])->assertRedirect(route('projects.index'));

    expect(Project::count())->toBe(0);
});

it('rejects a full_name that is not a plain owner/repo pair', function () {
    GithubConnection::create(['access_token' => 'gho_secrettoken', 'username' => 'octocat']);

    $this->post('/projects/from-github', [
        'full_name' => 'not-a-repo-and-also-https://evil.example.com/steal',
        'branch' => 'main',
    ])->assertInvalid('full_name');

    expect(Project::count())->toBe(0);
});

it('clones the repository with the token and registers a project', function () {
    GithubConnection::create(['access_token' => 'gho_secrettoken', 'username' => 'octocat']);
    Process::fake();

    $this->post('/projects/from-github', [
        'full_name' => 'octocat/hello-world',
        'branch' => 'main',
    ])->assertRedirect(route('projects.index'));

    $project = Project::first();
    expect($project->name)->toBe('hello-world');
    expect($project->path)->toBe($this->tmp.'/octocat-hello-world');
    expect($project->branch)->toBe('main');

    Process::assertRan(function ($process) {
        $command = $process->command;

        return is_array($command)
            && $command[0] === 'git'
            && $command[1] === 'clone'
            && str_contains($command[2], 'https://x-access-token:gho_secrettoken@github.com/octocat/hello-world.git');
    });

    // The token must not survive in the clone's remote URL.
    Process::assertRan(function ($process) {
        $command = $process->command;

        return is_array($command)
            && $command[3] === 'remote'
            && $command[4] === 'set-url'
            && $command[6] === 'https://github.com/octocat/hello-world.git';
    });
});

it('gives each project from github a unique name', function () {
    GithubConnection::create(['access_token' => 'gho_secrettoken', 'username' => 'octocat']);
    Project::factory()->create(['name' => 'hello-world']);
    Process::fake();

    $this->post('/projects/from-github', [
        'full_name' => 'octocat/hello-world',
        'branch' => 'main',
    ]);

    expect(Project::where('name', 'hello-world-2')->exists())->toBeTrue();
});
