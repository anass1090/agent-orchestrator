<?php

use App\Enums\Phase;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\Pipeline;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/pipeline-test-'.uniqid();
    File::ensureDirectoryExists($this->tmp);

    $git = fn (string $cwd, string ...$args) => Process::path($cwd)->run(['git', ...$args])->throw();

    // A bare remote plus a target project with one commit on main.
    $git($this->tmp, 'init', '--quiet', '--bare', 'remote.git');
    File::ensureDirectoryExists($this->tmp.'/project');
    $git($this->tmp.'/project', 'init', '--quiet', '--initial-branch=main');
    $git($this->tmp.'/project', 'config', 'user.email', 'test@example.com');
    $git($this->tmp.'/project', 'config', 'user.name', 'Test');
    File::put($this->tmp.'/project/README.md', "# Demo\n");
    $git($this->tmp.'/project', 'add', '.');
    $git($this->tmp.'/project', 'commit', '--quiet', '-m', 'init');
    $git($this->tmp.'/project', 'remote', 'add', 'origin', $this->tmp.'/remote.git');

    $this->project = Project::factory()->create(['name' => 'demo', 'path' => $this->tmp.'/project']);

    config([
        'pipeline.claude.binary' => base_path('tests/Fixtures/fake-claude'),
        'pipeline.worktree_path' => $this->tmp.'/worktrees',
        'pipeline.storage_path' => $this->tmp.'/storage',
        'pipeline.checks' => [
            'pint' => ['php', '-r', 'exit(0);'],
            'phpstan' => ['php', '-r', 'exit(0);'],
            'tests' => ['php', '-r', 'if (! file_exists("feature.txt")) { fwrite(STDERR, "feature.txt ontbreekt (0.12s)"); exit(1); }'],
        ],
    ]);

    $this->scenario = function (array $calls): void {
        File::put($this->tmp.'/scenario.json', json_encode(['calls' => $calls]));
        setProcessEnv('FAKE_CLAUDE_SCENARIO', $this->tmp.'/scenario.json');
    };

    $this->calls = fn (): array => array_map(
        fn (string $line) => json_decode($line, true),
        file($this->tmp.'/calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [],
    );

    $this->plan = ['write' => ['plan.md' => '# Plan', 'aannames.md' => '# Aannames']];
});

afterEach(function () {
    setProcessEnv('FAKE_CLAUDE_SCENARIO', null);
    setProcessEnv('ANTHROPIC_API_KEY', null);
    File::deleteDirectory($this->tmp);
});

function runPipeline(Project $project, string $prompt = 'Voeg een feature toe'): Run
{
    $pipeline = app(Pipeline::class);

    return $pipeline->execute($pipeline->start($project, $prompt))->refresh();
}

it('runs plan, build and checks, then commits, pushes and removes the worktree', function () {
    ($this->scenario)([$this->plan, ['write' => ['feature.txt' => 'done']]]);

    $run = runPipeline($this->project);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->stop_reason)->toBe('checks_passed')
        ->and($run->fix_attempts)->toBe(0)
        ->and($run->model)->toBe('fake-model')
        ->and($run->pushed)->toBeTrue()
        ->and($run->worktree_path)->toBeNull()
        ->and($run->input_tokens)->toBe(200)
        ->and($run->output_tokens)->toBe(100)
        ->and((float) $run->cost_usd)->toBe(0.02)
        ->and($run->duration_ms)->not->toBeNull();

    expect($run->steps()->orderBy('id')->pluck('phase')->map->value->all())
        ->toBe(['setup', 'plan', 'build', 'pint', 'phpstan', 'tests', 'commit', 'push', 'cleanup']);

    expect(is_dir($this->tmp.'/worktrees/demo-run-'.$run->id))->toBeFalse();

    $remoteFiles = Process::path($this->tmp.'/remote.git')->run(['git', 'ls-tree', '--name-only', '-r', $run->branch])->output();
    expect($remoteFiles)->toContain('plan.md', 'aannames.md', 'feature.txt');

    expect(File::get($run->storagePath('plan.md')))->toBe('# Plan');
});

it('records readable tool events with a gapless sequence', function () {
    ($this->scenario)([$this->plan, ['write' => ['feature.txt' => 'done']]]);

    $run = runPipeline($this->project);
    $sequences = $run->events()->orderBy('sequence')->pluck('sequence')->all();

    expect($sequences)->toBe(range(1, count($sequences)));

    expect($run->events()->where('type', 'tool_use')->where('phase', 'plan')->pluck('summary')->all())
        ->toBe(['Write plan.md', 'Write aannames.md']);

    expect($run->events()->where('type', 'tool_result')->first())
        ->tool->toBe('Write')
        ->summary->toBe('ok: File created successfully');
});

it('sends the failing check output to the agent, resuming the build session', function () {
    ($this->scenario)([$this->plan, [], ['write' => ['feature.txt' => 'done']]]);

    $run = runPipeline($this->project);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->fix_attempts)->toBe(1);

    $fixCall = ($this->calls)()[2]['args'];
    $prompt = end($fixCall);

    expect($fixCall)->toContain('--resume', 'session-2')
        ->and($prompt)->toContain('`tests` is gefaald', 'feature.txt ontbreekt');

    expect($run->steps()->where('phase', Phase::Tests)->orderBy('attempt')->pluck('status')->map->value->all())
        ->toBe(['failed', 'succeeded']);
});

it('stops early when a fix leaves the same failure behind', function () {
    ($this->scenario)([$this->plan, [], ['write' => ['other.txt' => 'x']], []]);

    $run = runPipeline($this->project);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_check)->toBe('tests')
        ->and($run->stop_reason)->toBe('repeated_failure')
        ->and($run->fix_attempts)->toBe(1);
});

it('gives up after the maximum number of fix attempts', function () {
    config(['pipeline.checks.tests' => ['php', '-r', 'echo "attempt ".uniqid(); exit(1);']]);
    ($this->scenario)([$this->plan, [], [], [], []]);

    $run = runPipeline($this->project);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->stop_reason)->toBe('max_fix_attempts')
        ->and($run->fix_attempts)->toBe(3)
        ->and($run->steps()->where('phase', Phase::Fix)->count())->toBe(3);
});

it('records a missing check tool as a failed check', function () {
    config(['pipeline.checks.phpstan' => ['./vendor/bin/phpstan', 'analyse']]);
    ($this->scenario)([$this->plan, [], [], [], []]);

    $run = runPipeline($this->project);

    expect($run->failed_check)->toBe('phpstan')
        ->and($run->stop_reason)->toBe('repeated_failure')
        ->and(File::get($run->steps()->where('phase', Phase::Phpstan)->first()->output_path))->toContain('Tool ontbreekt');
});

it('treats a non-zero exit as an error without trusting stdout, and still cleans up', function () {
    ($this->scenario)([['exit' => 1, 'stderr' => 'API Error: 429 rate_limit_error']]);

    $run = runPipeline($this->project);
    $plan = $run->steps()->where('phase', Phase::Plan)->first();

    expect($run->status)->toBe(RunStatus::Error)
        ->and($run->stop_reason)->toBe('agent_error')
        ->and($run->error)->toContain('exited with code 1', '429')
        ->and($plan->status)->toBe(StepStatus::Error)
        ->and($plan->exit_code)->toBe(1)
        ->and($run->worktree_path)->toBeNull()
        ->and(is_dir($this->tmp.'/worktrees/demo-run-'.$run->id))->toBeFalse();
});

it('treats a clean exit without a result event as an error', function () {
    ($this->scenario)([['write' => ['plan.md' => 'x'], 'no_result' => true]]);

    $run = runPipeline($this->project);

    expect($run->status)->toBe(RunStatus::Error)
        ->and($run->error)->toContain('no result event');
});

it('fails the run when the plan phase does not write both files', function () {
    ($this->scenario)([['write' => ['plan.md' => '# Plan', 'app/Code.php' => '<?php']]]);

    $run = runPipeline($this->project);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->stop_reason)->toBe('plan_missing')
        ->and($run->error)->toContain('aannames.md');
});

it('warns when the plan phase writes code', function () {
    ($this->scenario)([['write' => ['plan.md' => '#', 'aannames.md' => '#', 'app/Code.php' => '<?php']], ['write' => ['feature.txt' => 'x']]]);

    $run = runPipeline($this->project);

    expect($run->events()->where('type', 'pipeline.warning')->first()->payload['files'])->toBe(['app/Code.php']);
});

it('never passes the API key or the orchestrator database to the agent', function () {
    setProcessEnv('ANTHROPIC_API_KEY', 'sk-secret');
    ($this->scenario)([$this->plan, ['write' => ['feature.txt' => 'done']]]);

    runPipeline($this->project);

    foreach (($this->calls)() as $call) {
        expect($call['env']['ANTHROPIC_API_KEY'])->toBeFalse()
            ->and($call['env']['DB_CONNECTION'])->toBe('sqlite')
            ->and($call['env']['DB_DATABASE'])->toBe(':memory:');
    }
});

it('stores oversized payloads on disk and a truncated copy in the database', function () {
    config(['pipeline.payload_max_bytes' => 2000]);
    ($this->scenario)([['write' => ['plan.md' => str_repeat('x', 5000), 'aannames.md' => '#']], ['write' => ['feature.txt' => 'x']]]);

    $run = runPipeline($this->project);
    $event = $run->events()->where('type', 'tool_use')->where('summary', 'Write plan.md')->first();

    expect($event->payload_path)->not->toBeNull()
        ->and($event->payload['_truncated'])->toBeTrue()
        ->and(strlen(json_encode($event->payload)))->toBeLessThan(2000)
        ->and(File::get($event->payload_path))->toContain(str_repeat('x', 5000));
});

it('can stop after the plan phase and keep the worktree for debugging', function () {
    ($this->scenario)([$this->plan]);
    $pipeline = app(Pipeline::class);

    $run = $pipeline->execute($pipeline->start($this->project, 'x'), Phase::Plan, keepWorktree: true)->refresh();

    expect($run->status)->toBe(RunStatus::Paused)
        ->and(is_file($run->worktree_path.'/plan.md'))->toBeTrue();

    $pipeline->finish($run);

    expect($run->refresh()->worktree_path)->toBeNull();
});
