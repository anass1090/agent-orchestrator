<?php

use App\Models\GithubConnection;
use Illuminate\Support\Facades\Http;

it('tells you to fill in the credentials when none are saved yet', function () {
    $response = $this->get('/github/connect');

    $response->assertRedirect(route('connections.index'));
    expect(session('status'))->toContain('Client ID');
});

it('saves the oauth app credentials from the connections page', function () {
    $this->post('/github/credentials', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ])->assertRedirect(route('connections.index'));

    expect(GithubConnection::configured())->toBeTrue();
    expect(GithubConnection::current()->client_id)->toBe('test-client-id');
    expect(GithubConnection::current()->client_secret)->toBe('test-client-secret');
});

it('redirects to github with a csrf state', function () {
    GithubConnection::create(['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']);

    $response = $this->get('/github/connect');

    $response->assertRedirectContains('https://github.com/login/oauth/authorize');
    $response->assertRedirectContains('client_id=test-client-id');
    expect(session('github_oauth_state'))->not->toBeNull();
});

it('rejects a callback with a missing or wrong state', function () {
    $response = $this->get('/github/callback?code=abc&state=wrong');

    $response->assertRedirect(route('connections.index'));
    expect(session('status'))->toContain('ongeldige state');
    expect(GithubConnection::current())->toBeNull();
});

it('stores the connection after a valid callback', function () {
    GithubConnection::create(['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']);

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_faketoken']),
        'api.github.com/user' => Http::response(['login' => 'octocat', 'avatar_url' => 'https://example.test/avatar.png']),
    ]);

    $this->withSession(['github_oauth_state' => 'the-state'])
        ->get('/github/callback?code=abc&state=the-state')
        ->assertRedirect(route('connections.index'));

    $connection = GithubConnection::current();
    expect($connection->connected())->toBeTrue();
    expect($connection->username)->toBe('octocat');
    expect($connection->access_token)->toBe('gho_faketoken');
});

it('disconnects github but keeps the saved oauth app credentials', function () {
    GithubConnection::create([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'access_token' => 'gho_faketoken',
        'username' => 'octocat',
    ]);

    $this->post('/github/disconnect')->assertRedirect(route('connections.index'));

    expect(GithubConnection::summary())->toBeNull();
    expect(GithubConnection::configured())->toBeTrue();
});

it('refuses to list repositories when not connected', function () {
    $this->get('/github/repositories')->assertStatus(409);
});

it('lists the connected account\'s repositories, including private ones', function () {
    GithubConnection::create(['access_token' => 'gho_faketoken', 'username' => 'octocat']);

    Http::fake([
        'api.github.com/user/repos*' => Http::response([
            ['full_name' => 'octocat/public-repo', 'private' => false],
            ['full_name' => 'octocat/secret-repo', 'private' => true],
        ]),
    ]);

    $response = $this->get('/github/repositories');

    $response->assertOk();
    $response->assertJsonCount(2);
    $response->assertJsonFragment(['full_name' => 'octocat/secret-repo', 'private' => true]);
});
