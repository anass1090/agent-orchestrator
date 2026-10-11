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
    $this->tmp = sys_get_temp_dir().'/worktree-base-test-'.uniqid();
    File::ensureDirectoryExists($this->tmp);

    $this->git = fn (string $cwd, string ...$args) => Process::path($cwd)->run(['git', ...$args])->throw()->output();

    // A bare remote plus a project whose main is pushed to it.
    ($this->git)($this->tmp, 'init', '--quiet', '--bare', '--initial-branch=main', 'remote.git');
    File::ensureDirectoryExists($this->tmp.'/project');
    ($this->git)($this->tmp.'/project', 'init', '--quiet', '--initial-branch=main');
    ($this->git)($this->tmp.'/project', 'config', 'user.email', 'test@example.com');
    ($this->git)($this->tmp.'/project', 'config', 'user.name', 'Test');
    File::put($this->tmp.'/project/README.md', "# Demo\n");
    ($this->git)($this->tmp.'/project', 'add', '.');
    ($this->git)($this->tmp.'/project', 'commit', '--quiet', '-m', 'init');
    ($this->git)($this->tmp.'/project', 'remote', 'add', 'origin', $this->tmp.'/remote.git');

    $this->commit = function (string $cwd, string $file): string {
        File::put($cwd.'/'.$file, $file);
        ($this->git)($cwd, 'add', '.');
        ($this->git)($cwd, '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '--quiet', '-m', $file);

        return trim(($this->git)($cwd, 'rev-parse', 'HEAD'));
    };

    config(['pipeline.worktree_path' => $this->tmp.'/worktrees']);

    $this->project = Project::factory()->create(['name' => 'demo', 'path' => $this->tmp.'/project']);
    $this->run = Run::factory()->create(['project_id' => $this->project->id]);
    $this->step = $this->run->steps()->create(['phase' => Phase::Setup, 'attempt' => 0, 'status' => StepStatus::Running, 'started_at' => now()]);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('branches off the remote base branch when the local clone is behind', function () {
    ($this->git)($this->tmp.'/project', 'push', '--quiet', 'origin', 'main');
    ($this->git)($this->tmp, 'clone', '--quiet', 'remote.git', 'other');
    $remoteCommit = ($this->commit)($this->tmp.'/other', 'from-elsewhere.txt');
    ($this->git)($this->tmp.'/other', 'push', '--quiet', 'origin', 'main');

    app(Worktree::class)->create($this->run, $this->step);

    expect($this->run->refresh()->base_commit)->toBe($remoteCommit)
        ->and(is_file($this->run->worktree_path.'/from-elsewhere.txt'))->toBeTrue();
});

it('keeps the local base branch when it is ahead of the remote', function () {
    ($this->git)($this->tmp.'/project', 'push', '--quiet', 'origin', 'main');
    $localCommit = ($this->commit)($this->tmp.'/project', 'unpushed.txt');

    app(Worktree::class)->create($this->run, $this->step);

    expect($this->run->refresh()->base_commit)->toBe($localCommit);
});

it('falls back to the local base branch when the remote does not have it', function () {
    $localCommit = trim(($this->git)($this->tmp.'/project', 'rev-parse', 'main'));

    app(Worktree::class)->create($this->run, $this->step);

    expect($this->run->refresh()->base_commit)->toBe($localCommit);
});

it('fetches github projects from an explicit url without recording the token', function () {
    Process::fake([
        '*rev-parse*--verify*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    $this->project->update(['github_full_name' => 'octocat/demo']);
    GithubConnection::create(['access_token' => 'gho_supersecrettoken', 'username' => 'octocat']);

    app(Worktree::class)->create($this->run->refresh(), $this->step);

    $url = 'https://github.com/octocat/demo.git';

    Process::assertRan(fn ($process) => is_array($process->command)
        && in_array('fetch', $process->command, true)
        && in_array($url, $process->command, true)
        && in_array('credential.helper=', $process->command, true)
        && in_array("http.{$url}.extraHeader=Authorization: Basic ".base64_encode('x-access-token:gho_supersecrettoken'), $process->command, true));

    foreach ($this->run->events as $event) {
        expect($event->summary)->not->toContain('gho_supersecrettoken')
            ->and(json_encode($event->payload))->not->toContain('gho_supersecrettoken');
    }
});
