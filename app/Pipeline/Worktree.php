<?php

namespace App\Pipeline;

use App\Models\GithubConnection;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
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
        $path = $this->pathFor($run);
        $branch = $run->branch ?? $this->branchFor($run);

        $this->ensurePathIsFree($path);
        $this->addWorktree($run, $step, $path, $branch);

        $run->forceFill(['branch' => $branch, 'worktree_path' => $path])->save();
    }

    protected function ensurePathIsFree(string $path): void
    {
        if (is_dir($path)) {
            throw new RuntimeException("Worktree path [{$path}] already exists. Run pipeline:cleanup first.");
        }

        File::ensureDirectoryExists(dirname($path));
    }

    /**
     * Re-attach the branch if it already exists (resuming a run), otherwise
     * branch it off the project's current base commit.
     */
    protected function addWorktree(Run $run, RunStep $step, string $path, string $branch): void
    {
        $project = $run->project;
        $branchExists = $this->shell->run(
            $run,
            $step,
            ['git', 'rev-parse', '--verify', '--quiet', "refs/heads/{$branch}"],
            $project->path,
            mustSucceed: false
        )->successful();

        if ($branchExists) {
            $this->shell->run($run, $step, ['git', 'worktree', 'add', $path, $branch], $project->path);

            return;
        }

        $baseCommit = $this->resolveBaseCommit($run, $step);
        $this->shell->run(
            $run,
            $step,
            ['git', 'worktree', 'add', '-b', $branch, $path, $baseCommit],
            $project->path
        );
        $run->base_commit = $baseCommit;
    }

    /**
     * Branch off the remote's latest base branch rather than whatever the
     * project's clone last saw, unless the local branch is already ahead of
     * it (e.g. a merge whose push failed), so local work is never dropped.
     */
    protected function resolveBaseCommit(Run $run, RunStep $step): string
    {
        $project = $run->project;
        $localCommit = trim($this->shell->run(
            $run,
            $step,
            ['git', 'rev-parse', $project->branch],
            $project->path
        )->output());
        $remoteRef = $this->fetchBaseBranch($run, $step);

        if ($remoteRef === null) {
            return $localCommit;
        }

        $remoteCommit = trim($this->shell->run(
            $run,
            $step,
            ['git', 'rev-parse', $remoteRef],
            $project->path
        )->output());
        $localIsAhead = $this->shell->run(
            $run,
            $step,
            ['git', 'merge-base', '--is-ancestor', $remoteCommit, $localCommit],
            $project->path,
            mustSucceed: false
        )->successful();

        return $localIsAhead ? $localCommit : $remoteCommit;
    }

    /**
     * Best-effort: an unreachable remote, or one that doesn't have the base
     * branch yet, falls back to the local branch instead of failing the run.
     *
     * @return string|null the fetched remote-tracking ref, or null when nothing was fetched
     */
    protected function fetchBaseBranch(Run $run, RunStep $step): ?string
    {
        $project = $run->project;
        $remote = $project->remote;

        if (! $remote || ! $this->shell->run(
            $run,
            $step,
            ['git', 'remote', 'get-url', $remote],
            $project->path,
            mustSucceed: false
        )->successful()) {
            return null;
        }

        $remoteRef = "refs/remotes/{$remote}/{$project->branch}";
        $refspec = "+refs/heads/{$project->branch}:{$remoteRef}";
        $token = $this->githubToken($run);
        $url = $this->githubUrl($run);

        $fetched = $token === null
            ? $this->shell->run(
                $run,
                $step,
                ['git', 'fetch', '--quiet', $remote, $refspec],
                $project->path,
                mustSucceed: false
            )
            : $this->shell->runRedacted(
                $run,
                $step,
                ['git', ...$this->tokenFlags($url, $token), 'fetch', '--quiet', $url, $refspec],
                "git fetch {$url} {$refspec}",
                $project->path,
                mustSucceed: false,
            );

        return $fetched->successful() ? $remoteRef : null;
    }

    /**
     * Make the worktree runnable: dependencies and a safe .env.
     *
     * vendor/ is copied rather than symlinked: Composer's autoloader resolves
     * app/ relative to the real vendor location, so a symlink would load the
     * original project's classes instead of the worktree's.
     *
     * @throws FileNotFoundException
     */
    public function prepare(Run $run, RunStep $step): void
    {
        $project = $run->project;
        $path = (string) $run->worktree_path;

        if (is_dir($project->path.'/vendor') && ! is_dir($path.'/vendor')) {
            $this->shell->run(
                $run,
                $step,
                ['cp', '-a', '--reflink=auto', $project->path.'/vendor', $path.'/vendor'],
                $path,
                'setup'
            );
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

        $this->commitPendingChanges($run, $step, $path);
        $head = trim($this->shell->run($run, $step, ['git', 'rev-parse', 'HEAD'], $path)->output());

        if ($run->base_commit) {
            $this->writeDiffArtifact($run, $step, $path, $head);
        }

        return $head;
    }

    protected function commitPendingChanges(Run $run, RunStep $step, string $path): void
    {
        $this->shell->run($run, $step, ['git', 'add', '--all'], $path);

        $hasChanges = ! $this->shell->run(
            $run,
            $step,
            ['git', 'diff', '--cached', '--quiet'],
            $path,
            mustSucceed: false
        )->successful();

        if (! $hasChanges) {
            return;
        }

        $message = sprintf("pipeline run #%d\n\n%s", $run->id, $run->prompt);
        $this->shell->run(
            $run,
            $step,
            [...$this->authorFlags(), 'commit', '--no-verify', '--quiet', '-m', $message],
            $path
        );
    }

    /**
     * Keep a copy of what the run actually changed, for the "files" tab.
     */
    protected function writeDiffArtifact(Run $run, RunStep $step, string $path, string $head): void
    {
        $diff = $this->shell->run(
            $run,
            $step,
            ['git', 'diff', '--binary', $run->base_commit, $head],
            $path,
            mustSucceed: false
        );
        File::ensureDirectoryExists($run->storagePath());
        File::put($run->storagePath('final.diff'), $diff->output());
        $this->shell->run(
            $run,
            $step,
            ['git', 'diff', '--stat', $run->base_commit, $head],
            $path,
            mustSucceed: false
        );
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

        $hasRemote = $this->shell->run(
            $run,
            $step,
            ['git', 'remote', 'get-url', $remote],
            $run->project->path,
            mustSucceed: false
        )->successful();

        if (! $hasRemote) {
            return false;
        }

        $this->pushBranch($run, $step, $remote, (string) $run->branch);

        return true;
    }

    /**
     * Pushes any branch through the same GitHub-token handling as the run's
     * own branch, so other steps (e.g. the auto-merge into the base branch)
     * can push through the identical, security-hardened path.
     */
    public function pushBranch(Run $run, RunStep $step, string $remote, string $branch): void
    {
        $token = $this->githubToken($run);

        if ($token === null) {
            $this->shell->run(
                $run,
                $step,
                ['git', 'push', '--set-upstream', $remote, $branch],
                $run->project->path
            );

            return;
        }

        $this->pushWithToken($run, $step, $branch, $token);
    }

    /**
     * Projects registered from a GitHub repository have the credential
     * stripped from their origin remote right after cloning (so the agent
     * can never read it out of .git/config), so pushing needs it supplied
     * again here instead - transiently, never written to disk.
     *
     * Pushes to an explicit URL built from the project's own full name, never
     * to the "origin" remote name: the agent has shell access during the
     * build/fix phases and could run `git remote set-url origin <anything>`,
     * and an un-scoped credential would follow a rewritten remote anywhere.
     * The header is also host-scoped (http.<url>.extraHeader, not
     * http.extraHeader) as a second, independent guard against that same
     * rewrite making the token leak to a different host. Runs through
     * Shell::runRedacted(), not run(): the credential travels as a command
     * argument, which run() would otherwise log in full as a visible
     * pipeline.command event.
     */
    protected function pushWithToken(Run $run, RunStep $step, string $branch, string $token): void
    {
        $url = $this->githubUrl($run);

        $this->shell->runRedacted(
            $run,
            $step,
            ['git', ...$this->tokenFlags($url, $token), 'push', '--set-upstream', $url, $branch],
            "git push --set-upstream {$url} {$branch}",
            $run->project->path,
        );
    }

    /**
     * The GitHub token to push/fetch with, or null for projects not sourced
     * from GitHub (or when no account is connected).
     */
    protected function githubToken(Run $run): ?string
    {
        return $run->project->github_full_name !== null ? GithubConnection::current()?->access_token : null;
    }

    protected function githubUrl(Run $run): string
    {
        return "https://github.com/{$run->project->github_full_name}.git";
    }

    /**
     * @return list<string>
     */
    protected function tokenFlags(string $url, string $token): array
    {
        $header = 'Authorization: Basic '.base64_encode("x-access-token:{$token}");

        return ['-c', 'credential.helper=', '-c', "http.{$url}.extraHeader={$header}"];
    }

    /**
     * Remove the worktree directory; the branch is kept.
     */
    public function remove(Run $run, RunStep $step): void
    {
        $path = (string) $run->worktree_path;
        $projectPath = $run->project->path;

        if ($path !== '' && is_dir($path)) {
            $removed = $this->shell->run(
                $run,
                $step,
                ['git', 'worktree', 'remove', '--force', '--force', $path],
                $projectPath,
                mustSucceed: false
            )->successful();

            if (! $removed && $path === $this->pathFor($run)) {
                File::deleteDirectory($path);
            }
        }

        $this->shell->run($run, $step, ['git', 'worktree', 'prune'], $projectPath, mustSucceed: false);

        $run->forceFill(['worktree_path' => null])->save();
    }

    /**
     * @throws FileNotFoundException
     */
    protected function writeEnvFile(Run $run, RunStep $step): void
    {
        $project = $run->project;
        $path = (string) $run->worktree_path;
        $source = collect([
            $project->path.'/.env',
            $path.'/.env.example',
        ])->first(fn (string $file) => is_file($file));

        if ($source === null) {
            return;
        }

        $contents = File::get($source);

        foreach ($this->environment->overrides($project) as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents)
                ? (string) preg_replace($pattern, $line, $contents)
                : rtrim($contents)."\n".$line."\n";
        }

        File::put($path.'/.env', $contents);

        if (is_file($path.'/artisan') && preg_match('/^APP_KEY=\s*$/m', $contents)) {
            $this->shell->run(
                $run,
                $step,
                ['php', 'artisan', 'key:generate', '--force', '--no-interaction'],
                $path,
                'setup'
            );
        }
    }

    /**
     * The `-c user.name=`/`-c user.email=` flags every pipeline-authored
     * commit uses, shared with the auto-merge step.
     *
     * @return list<string>
     */
    public function authorFlags(): array
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
