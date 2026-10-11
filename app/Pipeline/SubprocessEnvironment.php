<?php

namespace App\Pipeline;

use App\Models\Project;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

class SubprocessEnvironment
{
    /**
     * Build the environment for a subprocess that runs inside a target project.
     *
     * Keys mapped to false are removed from the inherited environment. This
     * strips the API key (so Claude Code uses the logged-in subscription) and
     * every variable the orchestrator loaded from its own .env, which would
     * otherwise override the target project's .env.
     *
     * @return array<string, string|false>
     */
    public function for(?Project $project = null): array
    {
        $environment = [];

        foreach ($this->orchestratorKeys() as $key) {
            $environment[$key] = false;
        }

        foreach (config('pipeline.unset_env') as $key) {
            $environment[$key] = false;
        }

        $environment['GIT_TERMINAL_PROMPT'] = '0';
        $environment['COMPOSER_HOME'] = $this->composerHome();

        foreach ($this->overrides($project) as $key => $value) {
            $environment[$key] = (string) $value;
        }

        return $environment;
    }

    /**
     * The variables forced onto the target project, in its .env and process env.
     *
     * @return array<string, string>
     */
    public function overrides(?Project $project): array
    {
        return $project->env_overrides ?? config('pipeline.default_env_overrides');
    }

    /**
     * @return list<string>
     */
    protected function orchestratorKeys(): array
    {
        $path = app()->environmentFilePath();

        if (! is_file($path)) {
            return [];
        }

        return array_keys(Dotenv::parse((string) file_get_contents($path)));
    }

    /**
     * Composer refuses to run without COMPOSER_HOME or HOME. Under Sail,
     * supervisord switches the PHP process to the "sail" user without giving
     * it that user's HOME, leaving both unset. We only fill in COMPOSER_HOME,
     * not HOME: Claude Code falls back to the OS user's home directory (via
     * Node's os.homedir()) to find its login when HOME is unset, which is
     * where `claude auth login` actually stored it — forcing HOME elsewhere
     * would hide that login from every pipeline run.
     */
    protected function composerHome(): string
    {
        $path = storage_path('app/composer-home');

        File::ensureDirectoryExists($path);

        return $path;
    }
}
