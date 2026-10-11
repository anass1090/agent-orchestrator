<?php

use App\Enums\Phase;
use App\Enums\StepStatus;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\BranchMerger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/branch-merger-test-'.uniqid();
    File::ensureDirectoryExists($this->tmp);

    $this->git = fn (string $cwd, string ...$args) => Process::path($cwd)->run(['git', ...$args])->throw();

    ($this->git)($this->tmp, 'init', '--quiet', '--bare', 'remote.git');
    File::ensureDirectoryExists($this->tmp.'/project');
    ($this->git)($this->tmp.'/project', 'init', '--quiet', '--initial-branch=main');
    ($this->git)($this->tmp.'/project', 'config', 'user.email', 'test@example.com');
    ($this->git)($this->tmp.'/project', 'config', 'user.name', 'Test');
    File::put($this->tmp.'/project/shared.txt', "line one\nline two\n");
    ($this->git)($this->tmp.'/project', 'add', '.');
    ($this->git)($this->tmp.'/project', 'commit', '--quiet', '-m', 'init');
    ($this->git)($this->tmp.'/project', 'remote', 'add', 'origin', $this->tmp.'/remote.git');
    ($this->git)($this->tmp.'/project', 'push', '--quiet', 'origin', 'main');

    $this->project = Project::factory()->create(['name' => 'demo', 'path' => $this->tmp.'/project']);

    $this->makeRunBranch = function (string $branch): Run {
        ($this->git)($this->tmp.'/project', 'branch', $branch);

        return Run::factory()->create(['project_id' => $this->project->id, 'branch' => $branch, 'pushed' => true]);
    };

    $this->step = fn (Run $run) => $run->steps()->create(['phase' => Phase::Merge, 'attempt' => 0, 'status' => StepStatus::Running, 'started_at' => now()]);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('merges a clean branch into the base branch and pushes it', function () {
    $run = ($this->makeRunBranch)('pipeline/run-1');

    ($this->git)($this->tmp.'/project', 'checkout', '--quiet', 'pipeline/run-1');
    File::put($this->tmp.'/project/feature.txt', "new feature\n");
    ($this->git)($this->tmp.'/project', 'add', '.');
    ($this->git)($this->tmp.'/project', 'commit', '--quiet', '-m', 'feature');
    ($this->git)($this->tmp.'/project', 'checkout', '--quiet', 'main');

    $merged = app(BranchMerger::class)->merge($run, ($this->step)($run));

    expect($merged)->toBeTrue();

    $mainFiles = Process::path($this->tmp.'/remote.git')->run(['git', 'ls-tree', '--name-only', '-r', 'main'])->output();
    expect($mainFiles)->toContain('feature.txt');

    $localHead = trim(($this->git)($this->tmp.'/project', 'rev-parse', 'main')->output());
    $remoteHead = trim(Process::path($this->tmp.'/remote.git')->run(['git', 'rev-parse', 'main'])->output());
    expect($localHead)->toBe($remoteHead);

    // The project's own checkout (on main) was fast-forwarded to match, not just the ref.
    expect(File::exists($this->tmp.'/project/feature.txt'))->toBeTrue();
});

it('leaves a conflicting branch unmerged for a human to resolve', function () {
    $run = ($this->makeRunBranch)('pipeline/run-2');

    ($this->git)($this->tmp.'/project', 'checkout', '--quiet', 'pipeline/run-2');
    File::put($this->tmp.'/project/shared.txt', "branch change\nline two\n");
    ($this->git)($this->tmp.'/project', 'commit', '--quiet', '-am', 'branch edit');
    ($this->git)($this->tmp.'/project', 'checkout', '--quiet', 'main');

    File::put($this->tmp.'/project/shared.txt', "main change\nline two\n");
    ($this->git)($this->tmp.'/project', 'commit', '--quiet', '-am', 'main edit');
    ($this->git)($this->tmp.'/project', 'push', '--quiet', 'origin', 'main');

    $beforeHead = trim(($this->git)($this->tmp.'/project', 'rev-parse', 'main')->output());

    $merged = app(BranchMerger::class)->merge($run, ($this->step)($run));

    expect($merged)->toBeFalse();

    $afterHead = trim(($this->git)($this->tmp.'/project', 'rev-parse', 'main')->output());
    expect($afterHead)->toBe($beforeHead);

    $remoteHead = trim(Process::path($this->tmp.'/remote.git')->run(['git', 'rev-parse', 'main'])->output());
    expect($remoteHead)->toBe($beforeHead);
});
