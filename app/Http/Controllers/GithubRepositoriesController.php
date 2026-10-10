<?php

namespace App\Http\Controllers;

use App\GitHub\GithubRepositoryClient;
use App\Models\GithubConnection;
use Illuminate\Http\JsonResponse;

/**
 * Used by the project-creation picker to list repositories, including
 * private ones, the connected GitHub account can see.
 */
class GithubRepositoriesController extends Controller
{
    public function __invoke(GithubRepositoryClient $client): JsonResponse
    {
        $connection = GithubConnection::current();

        if (! $connection?->connected()) {
            return response()->json(['message' => 'GitHub is niet verbonden.'], 409);
        }

        return response()->json($client->list($connection->access_token));
    }
}
