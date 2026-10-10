<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\GitHub\GithubRepositoryCloner;
use App\Http\Requests\StoreProjectFromGithubRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Models\GithubConnection;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('projects/index', [
            'projects' => $this->projectsWithCounts(),
            'github' => GithubConnection::summary(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function projectsWithCounts(): array
    {
        return array_values(Project::query()
            ->withCount([
                'runs',
                'runs as succeeded_count' => fn ($query) => $query->where('status', RunStatus::Succeeded),
                'runs as failed_count' => fn ($query) => $query->where('status', RunStatus::Failed),
                'runs as error_count' => fn ($query) => $query->where('status', RunStatus::Error),
            ])
            ->withSum('runs', 'cost_usd')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
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
            ])
            ->all());
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

    public function storeFromGithub(StoreProjectFromGithubRequest $request, GithubRepositoryCloner $cloner): RedirectResponse
    {
        $connection = GithubConnection::current();

        if (! $connection?->connected()) {
            return to_route('projects.index')->with('status', 'GitHub is niet verbonden.');
        }

        $path = $cloner->clone($request->string('full_name')->value(), $connection->access_token);

        $project = Project::create([
            'name' => $this->uniqueProjectName($request->projectName()),
            'path' => $path,
            'branch' => $request->string('branch')->value(),
            'remote' => $request->string('remote')->value() ?: null,
            'github_full_name' => $request->string('full_name')->value(),
        ]);

        return to_route('projects.index')->with('status', "Project {$project->name} geregistreerd vanuit GitHub.");
    }

    protected function uniqueProjectName(string $base): string
    {
        $name = $base;
        $suffix = 2;

        while (Project::where('name', $name)->exists()) {
            $name = "{$base}-{$suffix}";
            $suffix++;
        }

        return $name;
    }
}
