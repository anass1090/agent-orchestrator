<?php

use App\Enums\Phase;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\BackgroundLauncher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/pipeline-web-'.uniqid();
    config(['pipeline.storage_path' => $this->storage]);
});

afterEach(function () {
    File::deleteDirectory($this->storage);
});

/**
 * A finished run with a few steps, events and stored files.
 */
function finishedRun(): Run
{
    $run = Run::factory()->create([
        'prompt' => 'Voeg een farewell-functie toe',
        'status' => RunStatus::Failed,
        'failed_check' => 'tests',
        'stop_reason' => 'repeated_failure',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'duration_ms' => 300000,
    ]);

    $run->steps()->create(['phase' => Phase::Plan, 'attempt' => 0, 'status' => StepStatus::Succeeded, 'started_at' => now()->subMinutes(5), 'duration_ms' => 60000]);
    $run->steps()->create(['phase' => Phase::Tests, 'attempt' => 0, 'status' => StepStatus::Failed, 'started_at' => now()->subMinutes(1), 'duration_ms' => 4000, 'exit_code' => 1]);

    $run->events()->create(['phase' => Phase::Plan, 'sequence' => 1, 'type' => 'tool_use', 'tool' => 'Read', 'summary' => 'Read app/Models/Invoice.php', 'payload' => ['block' => ['name' => 'Read']], 'occurred_at' => now()->subMinutes(4)]);
    $run->events()->create(['phase' => Phase::Plan, 'sequence' => 2, 'type' => 'system.thinking_tokens', 'occurred_at' => now()->subMinutes(4)]);
    $run->events()->create(['phase' => Phase::Tests, 'sequence' => 3, 'type' => 'check.result', 'summary' => 'tests failed (exit 1)', 'occurred_at' => now()]);

    File::ensureDirectoryExists($run->storagePath('checks'));
    File::put($run->storagePath('plan.md'), "# Het plan\n\n<script>alert(1)</script>");
    File::put($run->storagePath('checks/0-tests.log'), 'FAILED FarewellTest');

    return $run;
}

it('lists runs with their status and project', function () {
    $run = finishedRun();

    $this->get('/runs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('runs/index')
            ->has('runs.data', 1)
            ->where('runs.data.0.prompt', 'Voeg een farewell-functie toe')
            ->where('runs.data.0.project.name', $run->project->name)
            ->where('runs.data.0.status', 'failed')
            ->where('runs.data.0.failed_check', 'tests'));
});

it('asks to register a project before a run can be started', function () {
    $this->get('/runs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('runs/index')
        ->has('projects', 0));
});

it('starts a run in the background and redirects to it', function () {
    $project = Project::factory()->create();

    $this->mock(BackgroundLauncher::class)
        ->shouldReceive('launch')
        ->once()
        ->withArgs(fn (Run $run, array $arguments) => $arguments === ['pipeline:run', '--run='.$run->id, '--until=plan']);

    $response = $this->post('/runs', ['project_id' => $project->id, 'prompt' => 'Voeg een CSV-export toe', 'until' => 'plan']);

    $run = Run::sole();
    $response->assertRedirect(route('runs.show', $run));
    expect($run->status)->toBe(RunStatus::Pending)
        ->and($run->prompt)->toBe('Voeg een CSV-export toe');
});

it('marks the run as an error when it cannot be launched', function () {
    $project = Project::factory()->create();
    $this->mock(BackgroundLauncher::class)->shouldReceive('launch')->andThrow(new RuntimeException('php not found'));

    $this->post('/runs', ['project_id' => $project->id, 'prompt' => 'Voeg een CSV-export toe']);

    expect(Run::sole())->status->toBe(RunStatus::Error)->error->toBe('php not found');
});

it('validates the run form', function () {
    $this->post('/runs', ['prompt' => 'kort'])->assertSessionHasErrors(['project_id', 'prompt']);
});

it('launches the real detached command', function () {
    Process::fake();
    $run = Run::factory()->create();

    app(BackgroundLauncher::class)->launch($run, ['pipeline:run', '--run='.$run->id]);

    Process::assertRan(fn ($process) => str_starts_with($process->command, 'setsid ')
        && str_contains($process->command, "'pipeline:run' '--run={$run->id}'")
        && str_ends_with($process->command, '< /dev/null &'));
});

it('shows a run with its phases, events and rendered plan', function () {
    $run = finishedRun();

    $this->get(route('runs.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('runs/show')
            ->where('run.status', 'failed')
            ->has('run.steps', 2)
            ->has('events', 2)
            ->where('events.0.summary', 'Read app/Models/Invoice.php')
            ->where('events.1.summary', 'tests failed (exit 1)')
            ->where('events.1.kind', 'check-failed')
            ->where('lastSequence', 3)
            ->where('documents.0.label', 'Plan')
            ->where('documents.0.html', fn (string $html) => str_contains($html, '<h1>Het plan</h1>') && ! str_contains($html, '<script>'))
            ->where('files.0.path', 'checks/0-tests.log')
            ->where('phases', ['setup']));
});

it('shows noise events on request', function () {
    $this->get(route('runs.show', [finishedRun(), 'all' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 3)
            ->where('events.1.type', 'system.thinking_tokens'));
});

it('returns new events and fragments for live polling', function () {
    $run = finishedRun();

    $this->getJson(route('runs.live', [$run, 'after' => 1]))
        ->assertOk()
        ->assertJson(['run' => ['status' => 'failed', 'busy' => false], 'last_sequence' => 3])
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.summary', 'tests failed (exit 1)');
});

it('returns an event payload, from disk when it was too large', function () {
    $run = finishedRun();

    $this->getJson(route('runs.events.payload', [$run, 1]))->assertJson(['block' => ['name' => 'Read']]);

    File::ensureDirectoryExists($run->storagePath('payloads'));
    File::put($run->storagePath('payloads/3.json'), json_encode(['full' => str_repeat('x', 10)]));
    $run->events()->where('sequence', 3)->update(['payload_path' => $run->storagePath('payloads/3.json')]);

    $this->getJson(route('runs.events.payload', [$run, 3]))->assertJson(['full' => 'xxxxxxxxxx']);
});

it('does not serve an event of another run', function () {
    $run = finishedRun();
    $other = Run::factory()->create();

    $this->getJson(route('runs.events.payload', [$other, 1]))->assertNotFound();
});

it('serves stored files as text but never outside the run directory', function () {
    $run = finishedRun();
    File::put($this->storage.'/secret.txt', 'secret');

    $this->get(route('runs.files', [$run, 'checks/0-tests.log']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('FAILED FarewellTest');

    $this->get('/runs/'.$run->id.'/files/../secret.txt')->assertNotFound();
    $this->get('/runs/'.$run->id.'/files/..%2Fsecret.txt')->assertNotFound();
});

it('aborts a run that never started', function () {
    $run = Run::factory()->create(['status' => RunStatus::Pending]);

    $this->post(route('runs.stop', $run))->assertRedirect();

    expect($run->refresh()->status)->toBe(RunStatus::Aborted);
});

it('does not signal a process that is not a pipeline command', function () {
    $run = Run::factory()->create(['status' => RunStatus::Running, 'pid' => getmypid()]);

    expect($run->hasLiveProcess())->toBeFalse();

    $this->post(route('runs.stop', $run))->assertSessionHas('status', 'Deze run draait niet.');
});

it('deletes a run and its stored files', function () {
    $run = finishedRun();

    $this->delete(route('runs.destroy', $run))->assertRedirect(route('runs.index'));

    expect(Run::find($run->id))->toBeNull()
        ->and(File::exists($run->storagePath()))->toBeFalse();
});

it('refuses to delete a busy run', function () {
    $run = Run::factory()->create(['status' => RunStatus::Pending]);

    $this->delete(route('runs.destroy', $run))->assertSessionHas('status');

    expect(Run::find($run->id))->not->toBeNull();
});

it('runs a single phase in the background', function () {
    $run = finishedRun();
    $this->mock(BackgroundLauncher::class)
        ->shouldReceive('launch')->once()
        ->withArgs(fn (Run $launched, array $arguments) => $arguments === ['pipeline:phase', (string) $run->id, 'setup']);

    $this->post(route('runs.phase', [$run, 'setup']))->assertRedirect();

    expect($run->refresh()->status)->toBe(RunStatus::Pending);
});

it('refuses to run a phase while the run is busy', function () {
    $run = Run::factory()->create(['status' => RunStatus::Pending]);
    $this->mock(BackgroundLauncher::class)->shouldNotReceive('launch');

    $this->post(route('runs.phase', [$run, 'plan']))->assertSessionHas('status');
});

it('rejects unknown phases', function () {
    $this->post(route('runs.phase', [finishedRun(), 'deploy']))->assertNotFound();
});

it('lists projects with their success rate', function () {
    $run = finishedRun();
    Run::factory()->for($run->project)->create(['status' => RunStatus::Succeeded]);

    $this->get('/projects')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('projects/index')
        ->where('projects.0.name', $run->project->name)
        ->where('projects.0.succeeded_count', 1)
        ->where('projects.0.failed_count', 1)
        ->where('projects.0.runs_count', 2));
});

it('deletes a project, its runs and its cloned directory', function () {
    $path = sys_get_temp_dir().'/pipeline-cloned-'.uniqid();
    File::ensureDirectoryExists($path);
    $project = Project::factory()->create(['path' => $path, 'github_full_name' => 'octocat/demo']);
    $run = Run::factory()->for($project)->create(['status' => RunStatus::Succeeded]);

    $this->delete(route('projects.destroy', $project))->assertRedirect(route('projects.index'));

    expect(Project::find($project->id))->toBeNull()
        ->and(Run::find($run->id))->toBeNull()
        ->and(is_dir($path))->toBeFalse();
});

it('keeps a manually-registered project\'s directory on disk when deleted', function () {
    $path = sys_get_temp_dir().'/pipeline-manual-'.uniqid();
    File::ensureDirectoryExists($path);
    $project = Project::factory()->create(['path' => $path, 'github_full_name' => null]);

    $this->delete(route('projects.destroy', $project));

    expect(is_dir($path))->toBeTrue();

    File::deleteDirectory($path);
});

it('refuses to delete a project with a busy run', function () {
    $project = Project::factory()->create();
    Run::factory()->for($project)->create(['status' => RunStatus::Pending]);

    $this->delete(route('projects.destroy', $project))->assertSessionHas('status');

    expect(Project::find($project->id))->not->toBeNull();
});
