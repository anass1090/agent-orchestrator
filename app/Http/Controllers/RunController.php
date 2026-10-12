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

        try {
            $launcher->launch($run, ['pipeline:run', '--run='.$run->id]);
        } catch (Throwable $e) {
            $run->forceFill(['status' => RunStatus::Error, 'stop_reason' => 'launch_failed', 'error' => $e->getMessage()])->save();
        }

        return to_route('runs.show', $run);
    }

    /**
     * Refuses while the run is busy, so a live process never keeps writing
     * to a run that was just deleted out from under it.
     */
    public function destroy(Run $run, Pipeline $pipeline): RedirectResponse
    {
        if ($run->isBusy()) {
            return back()->with('status', 'Stop deze run eerst voordat je hem verwijdert.');
        }

        $pipeline->discard($run);
        $run->delete();

        return to_route('runs.index')->with('status', 'Run verwijderd.');
    }

    public function show(Run $run, EventFormatter $formatter): Response
    {
        $run->load(['project', 'steps' => fn ($query) => $query->orderBy('id')]);

        $events = $run->events()->orderBy('sequence')->get()
            ->reject(fn ($event) => $formatter->isNoise($event) || $formatter->isVerbose($event))
            ->each->setRelation('run', $run)
            ->values();

        return Inertia::render('runs/show', [
            'run' => RunResource::make($run)->resolve(),
            'events' => RunEventResource::collection($events)->resolve(),
            'lastSequence' => (int) $run->events()->max('sequence'),
            'files' => $this->files($run),
        ]);
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
