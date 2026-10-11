<?php

namespace App\GitHub;

use App\Models\GithubConnection;
use Illuminate\Support\Facades\Http;

/**
 * The OAuth App dance: build the authorize link, exchange its code for an
 * access token, and look up who that token belongs to. The client id/secret
 * come from the Connections page, not from config/env - set up once there.
 */
class GithubOAuthClient
{
    public function authorizeUrl(string $state): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => GithubConnection::current()?->client_id,
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'repo',
            'state' => $state,
        ]);
    }

    public function exchangeCodeForToken(string $code): string
    {
        $connection = GithubConnection::current();

        $response = Http::asForm()
            ->withHeaders(['Accept' => 'application/json'])
            ->post('https://github.com/login/oauth/access_token', [
                'client_id' => $connection?->client_id,
                'client_secret' => $connection?->client_secret,
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ])
            ->throw();

        return (string) $response->json('access_token');
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchAuthenticatedUser(string $token): array
    {
        return Http::withToken($token)
            ->get('https://api.github.com/user')
            ->throw()
            ->json();
    }

    protected function redirectUri(): string
    {
        return route('github.callback');
    }
}
