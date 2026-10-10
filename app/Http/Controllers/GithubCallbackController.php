<?php

namespace App\Http\Controllers;

use App\GitHub\GithubOAuthClient;
use App\Models\GithubConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GithubCallbackController extends Controller
{
    public function __invoke(Request $request, GithubOAuthClient $client): RedirectResponse
    {
        if (! $this->stateIsValid($request)) {
            return to_route('connections.index')->with('status', 'GitHub-koppeling mislukt: ongeldige state.');
        }

        $token = $client->exchangeCodeForToken($request->string('code')->value());
        $user = $client->fetchAuthenticatedUser($token);

        $this->storeConnection($token, $user);

        return to_route('connections.index')->with('status', "GitHub verbonden als {$user['login']}.");
    }

    protected function stateIsValid(Request $request): bool
    {
        $state = session()->pull('github_oauth_state');

        return $state !== null && hash_equals($state, (string) $request->query('state'));
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function storeConnection(string $token, array $user): void
    {
        $connection = GithubConnection::current() ?? new GithubConnection;

        $connection->forceFill([
            'access_token' => $token,
            'username' => $user['login'],
            'avatar_url' => $user['avatar_url'] ?? null,
            'connected_at' => now(),
        ])->save();
    }
}
