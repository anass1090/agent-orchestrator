<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\GitHub\GithubRepositoryCloner;
use App\Http\Requests\StoreProjectFromGithubRequest;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\Pipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
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

    /**
     * Deletes its runs (and their worktrees/stored files) along with it, and
     * the cloned directory for a GitHub-sourced project - but never a
     * manually-registered one's path, since that is the user's own existing
     * repository, not ours to remove.
     */
    public function destroy(Project $project, Pipeline $pipeline): RedirectResponse
    {
        if ($project->runs->contains(fn (Run $run) => $run->isBusy())) {
            return back()->with('status', 'Stop alle lopende runs van dit project eerst.');
        }

        $project->runs->each(fn (Run $run) => $pipeline->discard($run));

        if ($project->github_full_name !== null) {
            File::deleteDirectory($project->path);
        }

        $project->delete();

        return to_route('projects.index')->with('status', "Project {$project->name} verwijderd.");
    }
}
