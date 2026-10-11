<?php

namespace App\Http\Controllers;

use App\Claude\ClaudeAuthenticator;
use Illuminate\Http\JsonResponse;

/**
 * Starts a `claude auth login` attempt and hands back the URL to visit.
 */
class ClaudeLoginController extends Controller
{
    public function __invoke(ClaudeAuthenticator $authenticator): JsonResponse
    {
        return response()->json(['authorize_url' => $authenticator->start()]);
    }
}
