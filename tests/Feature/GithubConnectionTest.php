<?php

use App\Models\GithubConnection;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.github.client_id' => 'test-client-id',
        'services.github.client_secret' => 'test-client-secret',
        'services.github.redirect' => 'http://localhost/github/callback',
    ]);
});

it('tells you to configure GitHub when no client id is set', function () {
    config(['services.github.client_id' => null]);

    $response = $this->get('/github/connect');

    $response->assertRedirect(route('connections.index'));
    expect(session('status'))->toContain('niet geconfigureerd');
});

it('redirects to github with a csrf state', function () {
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

it('disconnects github', function () {
    GithubConnection::create(['access_token' => 'gho_faketoken', 'username' => 'octocat']);

    $this->post('/github/disconnect')->assertRedirect(route('connections.index'));

    expect(GithubConnection::current())->toBeNull();
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
