<?php

namespace App\Http\Controllers;

use App\GitHub\GithubOAuthClient;
use App\Models\GithubConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class GithubConnectController extends Controller
{
    public function __invoke(GithubOAuthClient $client): RedirectResponse
    {
        if (! GithubConnection::configured()) {
            return to_route('connections.index')->with('status', 'Vul eerst de GitHub Client ID en Secret in hieronder.');
        }

        $state = Str::random(40);
        session(['github_oauth_state' => $state]);

        return redirect($client->authorizeUrl($state));
    }
}
