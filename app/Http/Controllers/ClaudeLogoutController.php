<?php

namespace App\Http\Controllers;

use App\Claude\ClaudeAuthenticator;
use Illuminate\Http\JsonResponse;

class ClaudeLogoutController extends Controller
{
    public function __invoke(ClaudeAuthenticator $authenticator): JsonResponse
    {
        $authenticator->logout();

        return response()->json(['ok' => true]);
    }
}
