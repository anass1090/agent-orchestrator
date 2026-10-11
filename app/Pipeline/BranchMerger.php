<?php

namespace App\Pipeline;

use App\Models\Run;
use App\Models\RunStep;

/**
 * After a run's branch has been pushed, tries to merge it straight into the
 * project's base branch. A clean merge is pushed automatically; a
 * conflicting one is aborted and left on its own branch for a human to
 * resolve by hand.
 */
class BranchMerger
{
    public function __construct(
        protected Shell $shell,
        protected Worktree $worktree,
    ) {}

    /**
     * @return bool whether the merge was clean and pushed
     */
    public function merge(Run $run, RunStep $step): bool
    {
        $path = $run->project->path;
        $base = (string) $run->project->branch;
        $branch = (string) $run->branch;

        if (! $this->isCheckedOutOn($run, $step, $base)) {
            return false;
        }

        $result = $this->shell->run($run, $step, [
            ...$this->worktree->authorFlags(), 'merge', '--no-ff', '-m', "Merge {$branch} into {$base}", $branch,
        ], $path, mustSucceed: false);

        if (! $result->successful()) {
            $this->shell->run($run, $step, ['git', 'merge', '--abort'], $path, mustSucceed: false);

            return false;
        }

        $remote = $run->project->remote;

        if ($remote) {
            $this->worktree->pushBranch($run, $step, $remote, $base);
        }

        return true;
    }

    /**
     * The project's own checkout might have been switched to something else
     * by hand since the run started; merging straight into whatever happens
     * to be checked out there would be wrong, so this only proceeds when
     * it's still on the base branch.
     */
    protected function isCheckedOutOn(Run $run, RunStep $step, string $base): bool
    {
        $current = $this->shell->run($run, $step, ['git', 'branch', '--show-current'], $run->project->path, mustSucceed: false)->output();

        return trim($current) === $base;
    }
}
