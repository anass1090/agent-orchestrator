<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGithubCredentialsRequest;
use App\Models\GithubConnection;
use Illuminate\Http\RedirectResponse;

/**
 * Saves the OAuth App's client id/secret from the Connections page, so
 * setting up the GitHub connection never requires editing .env.
 */
class GithubCredentialsController extends Controller
{
    public function __invoke(StoreGithubCredentialsRequest $request): RedirectResponse
    {
        $connection = GithubConnection::current() ?? new GithubConnection;

        $connection->forceFill([
            'client_id' => $request->string('client_id')->value(),
            'client_secret' => $request->string('client_secret')->value(),
        ])->save();

        return to_route('connections.index')->with('status', 'GitHub-gegevens opgeslagen.');
    }
}
