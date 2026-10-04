<?php

namespace App\Http\Controllers;

use App\Models\Run;
use App\Models\RunEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class RunEventPayloadController extends Controller
{
    /**
     * The full payload: from disk when it was too large for the database.
     */
    public function __invoke(Run $run, RunEvent $event): JsonResponse
    {
        abort_unless($event->run_id === $run->id, 404);

        if ($event->payload_path !== null && File::exists($event->payload_path) && str_starts_with(realpath($event->payload_path) ?: '', realpath($run->storagePath()) ?: '/nonexistent')) {
            return response()->json(json_decode(File::get($event->payload_path), true));
        }

        return response()->json($event->payload ?? new \stdClass);
    }
}
