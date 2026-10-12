<?php

namespace App\Http\Controllers;

use App\Http\Resources\RunEventResource;
use App\Http\Resources\RunResource;
use App\Models\Run;
use App\Pipeline\Reporters\EventFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Polled by the run page while a run is busy: the run with its steps plus the
 * events since a sequence number.
 */
class RunLiveController extends Controller
{
    public function __invoke(Request $request, Run $run, EventFormatter $formatter): JsonResponse
    {
        $run->load(['project', 'steps' => fn ($query) => $query->orderBy('id')]);
        $after = $request->integer('after');

        $events = $run->events()->where('sequence', '>', $after)->orderBy('sequence')->limit(500)->get();

        return response()->json([
            'run' => RunResource::make($run)->resolve(),
            'last_sequence' => $events->max('sequence') ?? $after,
            'events' => RunEventResource::collection(
                $events->reject(fn ($event) => $formatter->isNoise($event) || $formatter->isVerbose($event))
                    ->each->setRelation('run', $run)
                    ->values()
            )->resolve(),
        ]);
    }
}
