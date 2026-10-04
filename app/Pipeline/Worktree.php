<?php

namespace App\Pipeline;

use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Lifecycle of the isolated git worktree a run works in.
 */
class Worktree
{
    public function __construct(
        protected Shell $shell,
        protected SubprocessEnvironment $environment,
    ) {}

    public function pathFor(Run $run): string
    {
        $root = config('pipeline.worktree_path') ?: dirname($run->project->path).'/.pipeline-worktrees';

        return rtrim($root, '/').'/'.$run->project->name.'-run-'.$run->id;
    }

    public function branchFor(Run $run): string
    {
        return config('pipeline.branch_prefix').'run-'.$run->id;
    }

    /**
     * Create the worktree on a new branch, or re-attach an existing run branch.
     */
    public function create(Run $run, RunStep $step): void
    {
        $project = $run->project;
        $path = $this->pathFor($run);
        $branch = $run->branch ?? $this->branchFor($run);

        if (is_dir($path)) {
            throw new RuntimeException("Worktree path [{$path}] already exists. Run pipeline:cleanup first.");
        }

        File::ensureDirectoryExists(dirname($path));

        $branchExists = $this->shell->run($run, $step, ['git', 'rev-parse', '--verify', '--quiet', "refs/heads/{$branch}"], $project->path, mustSucceed: false)->successful();

        if ($branchExists) {
            $this->shell->run($run, $step, ['git', 'worktree', 'add', $path, $branch], $project->path);
        } else {
            $baseCommit = trim($this->shell->run($run, $step, ['git', 'rev-parse', $project->branch], $project->path)->output());
            $this->shell->run($run, $step, ['git', 'worktree', 'add', '-b', $branch, $path, $baseCommit], $project->path);
            $run->base_commit = $baseCommit;
        }

        $run->forceFill(['branch' => $branch, 'worktree_path' => $path])->save();
    }

    /**
     * Make the worktree runnable: dependencies and a safe .env.
     *
     * vendor/ is copied rather than symlinked: Composer's autoloader resolves
     * app/ relative to the real vendor location, so a symlink would load the
     * original project's classes instead of the worktree's.
     */
    public function prepare(Run $run, RunStep $step): void
    {
        $project = $run->project;
        $path = (string) $run->worktree_path;

        if (is_dir($project->path.'/vendor') && ! is_dir($path.'/vendor')) {
            $this->shell->run($run, $step, ['cp', '-a', '--reflink=auto', $project->path.'/vendor', $path.'/vendor'], $path, 'setup');
        }

        if (is_file($path.'/composer.json')) {
            $command = is_dir($path.'/vendor')
                ? ['composer', 'dump-autoload', '--no-interaction']
                : ['composer', 'install', '--no-interaction', '--no-progress'];

            $this->shell->run($run, $step, $command, $path, 'setup');
        }

        $this->writeEnvFile($run, $step);
    }

    /**
     * Commit everything the agent produced on the run branch. Returns the
     * resulting HEAD, which may also include commits the agent made itself.
     */
    public function commit(Run $run, RunStep $step): string
    {
        $path = (string) $run->worktree_path;

        $this->shell->run($run, $step, ['git', 'add', '--all'], $path);

        $hasChanges = ! $this->shell->run($run, $step, ['git', 'diff', '--cached', '--quiet'], $path, mustSucceed: false)->successful();

        if ($hasChanges) {
            $message = sprintf("pipeline run #%d\n\n%s", $run->id, $run->prompt);
            $this->shell->run($run, $step, [...$this->authorFlags(), 'commit', '--no-verify', '--quiet', '-m', $message], $path);
        }

        $head = trim($this->shell->run($run, $step, ['git', 'rev-parse', 'HEAD'], $path)->output());

        if ($run->base_commit) {
            $diff = $this->shell->run($run, $step, ['git', 'diff', '--binary', $run->base_commit, $head], $path, mustSucceed: false);
            File::ensureDirectoryExists($run->storagePath());
            File::put($run->storagePath('final.diff'), $diff->output());
            $this->shell->run($run, $step, ['git', 'diff', '--stat', $run->base_commit, $head], $path, mustSucceed: false);
        }

        return $head;
    }

    /**
     * @return bool whether the branch was pushed; false when the project has no remote
     */
    public function push(Run $run, RunStep $step): bool
    {
        $remote = $run->project->remote;

        if (! $remote) {
            return false;
        }

        $hasRemote = $this->shell->run($run, $step, ['git', 'remote', 'get-url', $remote], $run->project->path, mustSucceed: false)->successful();

        if (! $hasRemote) {
            return false;
        }

        $this->shell->run($run, $step, ['git', 'push', '--set-upstream', $remote, (string) $run->branch], $run->project->path);

        return true;
    }

    /**
     * Remove the worktree directory; the branch is kept.
     */
    public function remove(Run $run, RunStep $step): void
    {
        $path = (string) $run->worktree_path;
        $projectPath = $run->project->path;

        if ($path !== '' && is_dir($path)) {
            $removed = $this->shell->run($run, $step, ['git', 'worktree', 'remove', '--force', '--force', $path], $projectPath, mustSucceed: false)->successful();

            if (! $removed && $path === $this->pathFor($run)) {
                File::deleteDirectory($path);
            }
        }

        $this->shell->run($run, $step, ['git', 'worktree', 'prune'], $projectPath, mustSucceed: false);

        $run->forceFill(['worktree_path' => null])->save();
    }

    protected function writeEnvFile(Run $run, RunStep $step): void
    {
        $project = $run->project;
        $path = (string) $run->worktree_path;
        $source = collect([$project->path.'/.env', $path.'/.env.example'])->first(fn (string $file) => is_file($file));

        if ($source === null) {
            return;
        }

        $contents = File::get($source);

        foreach ($this->environment->overrides($project) as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents) ? (string) preg_replace($pattern, $line, $contents) : rtrim($contents)."\n".$line."\n";
        }

        File::put($path.'/.env', $contents);

        if (is_file($path.'/artisan') && preg_match('/^APP_KEY=\s*$/m', $contents)) {
            $this->shell->run($run, $step, ['php', 'artisan', 'key:generate', '--force', '--no-interaction'], $path, 'setup');
        }
    }

    /**
     * @return list<string>
     */
    protected function authorFlags(): array
    {
        $author = config('pipeline.commit_author');
        $flags = ['git'];

        if ($author['name']) {
            array_push($flags, '-c', 'user.name='.$author['name']);
        }

        if ($author['email']) {
            array_push($flags, '-c', 'user.email='.$author['email']);
        }

        return $flags;
    }
}
