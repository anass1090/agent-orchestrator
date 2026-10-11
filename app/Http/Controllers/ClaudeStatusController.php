<?php

namespace App\Http\Controllers;

use App\Claude\ClaudeAuthenticator;
use Illuminate\Http\JsonResponse;

/**
 * Polled by the Connections page while a login attempt is in progress.
 */
class ClaudeStatusController extends Controller
{
    public function __invoke(ClaudeAuthenticator $authenticator): JsonResponse
    {
        return response()->json($authenticator->status());
    }
}
