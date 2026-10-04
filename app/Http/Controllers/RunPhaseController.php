<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Pipeline\BackgroundLauncher;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Runs a single phase of an existing run in the background, for debugging.
 */
class RunPhaseController extends Controller
{
    /** @var list<string> */
    public const PHASES = ['setup', 'plan', 'build', 'checks', 'fixloop', 'finish'];

    public function __invoke(Run $run, string $phase, BackgroundLauncher $launcher): RedirectResponse
    {
        abort_unless(in_array($phase, self::PHASES, true), 404);

        if ($run->isBusy()) {
            return back()->with('status', 'Deze run is nog bezig. Stop hem eerst of wacht tot hij klaar is.');
        }

        $previousStatus = $run->status;

        // Marked busy before launching; the phase command then claims the run as running.
        $run->forceFill(['status' => RunStatus::Pending, 'pid' => null])->save();

        try {
            $launcher->launch($run, ['pipeline:phase', (string) $run->id, $phase]);
        } catch (Throwable $e) {
            $run->forceFill(['status' => $previousStatus])->save();

            return back()->with('status', 'Fase kon niet starten: '.$e->getMessage());
        }

        return back()->with('status', "Fase {$phase} gestart.");
    }
}
