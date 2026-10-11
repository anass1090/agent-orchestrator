<?php

namespace App\Http\Controllers;

use App\Claude\ClaudeAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Submits the code pasted from the Claude login page to the waiting attempt.
 */
class ClaudeLoginCodeController extends Controller
{
    public function __invoke(Request $request, ClaudeAuthenticator $authenticator): JsonResponse
    {
        $authenticator->submitCode($request->validate(['code' => ['required', 'string']])['code']);

        return response()->json(['ok' => true]);
    }
}
