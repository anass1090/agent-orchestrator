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
        $path = realpath($this->argument('path'));

        if ($path === false || ! is_dir($path.'/.git')) {
            $this->error("[{$this->argument('path')}] is geen git-repository.");

            return self::FAILURE;
        }

        $project = Project::updateOrCreate(
            ['name' => $this->argument('name')],
            ['path' => $path, 'branch' => $this->option('branch'), 'remote' => $this->option('remote') ?: null],
        );

        $this->components->info("Project [{$project->name}] geregistreerd: {$project->path} ({$project->branch})");

        return self::SUCCESS;
    }
}
