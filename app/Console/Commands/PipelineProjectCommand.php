<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pipeline:project
    {name : Naam waarmee je het project aanroept}
    {path : Absoluut pad naar de git-repository}
    {--branch=main : Basisbranch waarvan elke run vertrekt}
    {--remote=origin : Remote waarnaar de run-branch gepusht wordt; leeg laten om niet te pushen}')]
#[Description('Registreer een doelproject of werk het bij')]
class PipelineProjectCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->resolveRepositoryPath();

        if ($path === null) {
            return self::FAILURE;
        }

        $project = $this->saveProject($path);

        $this->components->info("Project [{$project->name}] geregistreerd: {$project->path} ({$project->branch})");

        return self::SUCCESS;
    }

    protected function resolveRepositoryPath(): ?string
    {
        $path = realpath($this->argument('path'));

        if ($path === false || ! is_dir($path.'/.git')) {
            $this->error("[{$this->argument('path')}] is geen git-repository.");

            return null;
        }

        return $path;
    }

    protected function saveProject(string $path): Project
    {
        return Project::updateOrCreate(
            ['name' => $this->argument('name')],
            ['path' => $path, 'branch' => $this->option('branch'), 'remote' => $this->option('remote') ?: null],
        );
    }
}
