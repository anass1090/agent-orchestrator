<?php

use App\Claude\ClaudeAuthenticator;
use App\Models\ClaudeLogin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/claude-auth-test-'.uniqid();
    File::ensureDirectoryExists($this->tmp);

    config(['pipeline.claude.binary' => base_path('tests/Fixtures/fake-claude-auth')]);
    setProcessEnv('FAKE_CLAUDE_AUTH_STATE', $this->tmp.'/state.json');
    setProcessEnv('FAKE_CLAUDE_AUTH_CODE', 'right-code');

    $this->authenticator = new ClaudeAuthenticator;

    $this->waitUntilLoggedIn = function (bool $expected) {
        $deadline = microtime(true) + 3;

        while (microtime(true) < $deadline) {
            if ((bool) $this->authenticator->status()['loggedIn'] === $expected) {
                return;
            }

            usleep(100_000);
        }
    };
});

afterEach(function () {
    if ($pid = ClaudeLogin::current()->pid) {
        Process::run(['kill', '-9', (string) $pid]);
    }

    setProcessEnv('FAKE_CLAUDE_AUTH_STATE', null);
    setProcessEnv('FAKE_CLAUDE_AUTH_CODE', null);
    File::deleteDirectory($this->tmp);
});

it('reports not logged in before any attempt', function () {
    expect($this->authenticator->status()['loggedIn'])->toBeFalse();
});

it('starts a login attempt and captures the authorize url', function () {
    $url = $this->authenticator->start();

    expect($url)->toBe('https://example.test/fake-authorize');
    expect(ClaudeLogin::current()->authorize_url)->toBe($url);
    expect(ClaudeLogin::current()->hasLiveProcess())->toBeTrue();
});

it('logs in once the right code is submitted', function () {
    $this->authenticator->start();

    $this->authenticator->submitCode('wrong-code');
    ($this->waitUntilLoggedIn)(false);
    expect($this->authenticator->status()['loggedIn'])->toBeFalse();

    $this->authenticator->submitCode('right-code');
    ($this->waitUntilLoggedIn)(true);
    expect($this->authenticator->status()['loggedIn'])->toBeTrue();
});

it('logs out and clears the in-progress login', function () {
    $this->authenticator->start();
    $this->authenticator->submitCode('right-code');
    ($this->waitUntilLoggedIn)(true);

    $this->authenticator->logout();

    expect($this->authenticator->status()['loggedIn'])->toBeFalse();
    expect(ClaudeLogin::current()->fifo_path)->toBeNull();
});
