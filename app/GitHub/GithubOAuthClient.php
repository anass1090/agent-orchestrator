<?php

namespace App\GitHub;

use Illuminate\Support\Facades\Http;

/**
 * The OAuth App dance: build the authorize link, exchange its code for an
 * access token, and look up who that token belongs to.
 */
class GithubOAuthClient
{
    public function authorizeUrl(string $state): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => config('services.github.redirect'),
            'scope' => 'repo',
            'state' => $state,
        ]);
    }

    public function exchangeCodeForToken(string $code): string
    {
        $response = Http::asForm()
            ->withHeaders(['Accept' => 'application/json'])
            ->post('https://github.com/login/oauth/access_token', [
                'client_id' => config('services.github.client_id'),
                'client_secret' => config('services.github.client_secret'),
                'code' => $code,
                'redirect_uri' => config('services.github.redirect'),
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
}
