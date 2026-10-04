<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Http\Requests\StoreRunRequest;
use App\Http\Resources\RunEventResource;
use App\Http\Resources\RunResource;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\BackgroundLauncher;
use App\Pipeline\Pipeline;
use App\Pipeline\Reporters\EventFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RunController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = Run::query()
            ->with(['project', 'steps' => fn ($query) => $query->orderBy('id')])
            ->when($request->integer('project'), fn ($query, $id) => $query->where('project_id', $id))
            ->when($request->string('status')->value(), fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('runs/index', [
            'runs' => RunResource::collection($runs),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'statuses' => array_column(RunStatus::cases(), 'value'),
            'filters' => [
                'project' => $request->integer('project') ?: null,
                'status' => $request->string('status')->value() ?: null,
            ],
        ]);
    }

    public function store(StoreRunRequest $request, Pipeline $pipeline, BackgroundLauncher $launcher): RedirectResponse
    {
        $run = $pipeline->start(Project::findOrFail($request->integer('project_id')), $request->string('prompt')->trim()->value());

        $arguments = ['pipeline:run', '--run='.$run->id];

        if ($request->filled('until')) {
            $arguments[] = '--until='.$request->string('until');
        }

        if ($request->boolean('keep_worktree')) {
            $arguments[] = '--keep-worktree';
        }

        try {
            $launcher->launch($run, $arguments);
        } catch (Throwable $e) {
            $run->forceFill(['status' => RunStatus::Error, 'stop_reason' => 'launch_failed', 'error' => $e->getMessage()])->save();
        }

        return to_route('runs.show', $run);
    }

    public function show(Request $request, Run $run, EventFormatter $formatter): Response
    {
        $run->load(['project', 'steps' => fn ($query) => $query->orderBy('id')]);
        $showAll = $request->boolean('all');

        $events = $run->events()->orderBy('sequence')->get()
            ->reject(fn ($event) => ! $showAll && $formatter->isNoise($event))
            ->each->setRelation('run', $run)
            ->values();

        return Inertia::render('runs/show', [
            'run' => RunResource::make($run)->resolve(),
            'events' => RunEventResource::collection($events)->resolve(),
            'lastSequence' => (int) $run->events()->max('sequence'),
            'showAll' => $showAll,
            'phases' => $this->phases($run),
            'documents' => $this->documents($run),
            'files' => $this->files($run),
        ]);
    }

    /**
     * The phases that can be run on their own once the run is idle: only setup
     * until there is a worktree to work in.
     *
     * @return list<string>
     */
    protected function phases(Run $run): array
    {
        if ($run->isBusy()) {
            return [];
        }

        return $run->worktree_path && is_dir($run->worktree_path)
            ? ['plan', 'build', 'checks', 'fixloop', 'finish']
            : ['setup'];
    }

    /**
     * The agent's plan and assumptions, as copied at the end of the plan phase,
     * rendered to HTML with any HTML in the markdown escaped.
     *
     * @return list<array{label: string, html: string}>
     */
    protected function documents(Run $run): array
    {
        return array_values(collect(['plan.md' => 'Plan', 'aannames.md' => 'Aannames'])
            ->filter(fn (string $label, string $file) => File::exists($run->storagePath($file)))
            ->map(fn (string $label, string $file) => [
                'label' => $label,
                'html' => Str::markdown(File::get($run->storagePath($file)), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            ])
            ->all());
    }

    /**
     * Every file stored for this run, relative to its storage directory.
     *
     * @return list<array{path: string, url: string}>
     */
    protected function files(Run $run): array
    {
        if (! File::isDirectory($run->storagePath())) {
            return [];
        }

        return array_values(collect(File::allFiles($run->storagePath()))
            ->map(fn ($file) => $file->getRelativePathname())
            ->reject(fn (string $path) => str_starts_with($path, 'payloads/'))
            ->sort()
            ->map(fn (string $path) => ['path' => $path, 'url' => route('runs.files', [$run, $path])])
            ->all());
    }
}
