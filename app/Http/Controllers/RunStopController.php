<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Http\RedirectResponse;

class RunStopController extends Controller
{
    /**
     * Ask the run's process to stop. It traps SIGTERM, stops the current step
     * and then commits, pushes and removes the worktree as usual.
     */
    public function __invoke(Run $run): RedirectResponse
    {
        if ($run->status === RunStatus::Running && $run->hasLiveProcess()) {
            posix_kill((int) $run->pid, SIGTERM);

            return back()->with('status', 'Run wordt gestopt. De huidige stap wordt afgebroken, daarna volgen commit, push en opruimen.');
        }

        if ($run->status === RunStatus::Pending) {
            $run->forceFill(['status' => RunStatus::Aborted, 'stop_reason' => 'aborted'])->save();

            return back()->with('status', 'Run afgebroken voordat hij startte.');
        }

        return back()->with('status', 'Deze run draait niet.');
    }
}
