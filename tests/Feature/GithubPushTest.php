<?php

use App\Enums\Phase;
use App\Enums\StepStatus;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\Worktree;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/github-push-test-'.uniqid();
    File::ensureDirectoryExists($this->tmp);

    $git = fn (string $cwd, string ...$args) => Process::path($cwd)->run(['git', ...$args])->throw();

    // A bare remote plus a project with one commit on main, as if cloned
    // from GitHub with the token already stripped from its origin url. The
    // remote name itself ("origin") must never be trusted for a token push:
    // the agent can rewrite it during build/fix, so it only ever stands in
    // for the "does a remote exist at all" check.
    $git($this->tmp, 'init', '--quiet', '--bare', 'remote.git');
    File::ensureDirectoryExists($this->tmp.'/project');
    $git($this->tmp.'/project', 'init', '--quiet', '--initial-branch=main');
    $git($this->tmp.'/project', 'config', 'user.email', 'test@example.com');
    $git($this->tmp.'/project', 'config', 'user.name', 'Test');
    File::put($this->tmp.'/project/README.md', "# Demo\n");
    $git($this->tmp.'/project', 'add', '.');
    $git($this->tmp.'/project', 'commit', '--quiet', '-m', 'init');
    $git($this->tmp.'/project', 'remote', 'add', 'origin', $this->tmp.'/remote.git');

    $this->project = Project::factory()->create([
        'name' => 'demo',
        'path' => $this->tmp.'/project',
        'github_full_name' => 'octocat/demo',
    ]);

    $this->run = Run::factory()->create(['project_id' => $this->project->id, 'branch' => 'main']);
    $this->step = $this->run->steps()->create(['phase' => Phase::Push, 'attempt' => 0, 'status' => StepStatus::Running, 'started_at' => now()]);

    GithubConnection::create(['access_token' => 'gho_supersecrettoken', 'username' => 'octocat']);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('pushes to an explicit github url, never to whatever "origin" resolves to', function () {
    Process::fake();

    app(Worktree::class)->push($this->run, $this->step);

    Process::assertRan(function ($process) {
        $command = $process->command;

        if (! is_array($command) || $command[0] !== 'git' || end($command) !== 'main') {
            return false;
        }

        $url = 'https://github.com/octocat/demo.git';

        return in_array('push', $command, true)
            && in_array($url, $command, true)
            && in_array("http.{$url}.extraHeader=Authorization: Basic ".base64_encode('x-access-token:gho_supersecrettoken'), $command, true)
            && in_array('credential.helper=', $command, true);
    });
});

it('never records the token in the push event', function () {
    Process::fake();

    app(Worktree::class)->push($this->run, $this->step);

    $events = $this->run->events;
    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->summary)->not->toContain('gho_supersecrettoken');
        expect(json_encode($event->payload))->not->toContain('gho_supersecrettoken');
    }
});

it('pushes to the named remote without a token for projects not sourced from github', function () {
    $this->project->update(['github_full_name' => null]);

    $pushed = app(Worktree::class)->push($this->run, $this->step);

    expect($pushed)->toBeTrue();

    $log = Process::path($this->tmp.'/remote.git')->run(['git', 'log', '--oneline', 'main'])->output();
    expect($log)->toContain('init');
});
