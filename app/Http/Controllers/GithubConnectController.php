<?php

namespace App\Http\Controllers;

use App\GitHub\GithubOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class GithubConnectController extends Controller
{
    public function __invoke(GithubOAuthClient $client): RedirectResponse
    {
        if (! config('services.github.client_id')) {
            return to_route('connections.index')->with('status', 'GitHub is niet geconfigureerd: GITHUB_CLIENT_ID/GITHUB_CLIENT_SECRET ontbreken in .env.');
        }

        $state = Str::random(40);
        session(['github_oauth_state' => $state]);

        return redirect($client->authorizeUrl($state));
    }
}
