<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        $projects = Project::query()
            ->withCount([
                'runs',
                'runs as succeeded_count' => fn ($query) => $query->where('status', RunStatus::Succeeded),
                'runs as failed_count' => fn ($query) => $query->where('status', RunStatus::Failed),
                'runs as error_count' => fn ($query) => $query->where('status', RunStatus::Error),
            ])
            ->withSum('runs', 'cost_usd')
            ->orderBy('name')
            ->get();

        return Inertia::render('projects/index', [
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'path' => $project->path,
                'branch' => $project->branch,
                'remote' => $project->remote,
                'runs_count' => $project->runs_count,
                'succeeded_count' => $project->succeeded_count,
                'failed_count' => $project->failed_count,
                'error_count' => $project->error_count,
                'cost_usd' => (float) $project->runs_sum_cost_usd,
            ]),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create([
            'name' => $request->string('name')->value(),
            'path' => realpath($request->string('path')->value()),
            'branch' => $request->string('branch')->value(),
            'remote' => $request->string('remote')->value() ?: null,
        ]);

        return to_route('projects.index')->with('status', "Project {$project->name} geregistreerd.");
    }
}
