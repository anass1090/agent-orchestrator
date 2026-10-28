<?php

namespace App\GitHub;

use Illuminate\Support\Facades\Http;

class GithubRepositoryClient
{
    /**
     * The connected account's repositories, including private ones it can
     * see, most recently updated first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $token): array
    {
        return Http::withToken($token)
            ->get('https://api.github.com/user/repos', [
                'per_page' => 100,
                'sort' => 'updated',
                'affiliation' => 'owner,collaborator',
            ])
            ->throw()
            ->json();
    }

    /**
     * Whether this token is still accepted by GitHub, i.e. not revoked or
     * expired on its side. Anything other than a 401 counts as valid here,
     * so a transient GitHub/network hiccup never blocks a run on its own.
     */
    public function verify(string $token): bool
    {
        return Http::withToken($token)->get('https://api.github.com/user')->status() !== 401;
    }
}
