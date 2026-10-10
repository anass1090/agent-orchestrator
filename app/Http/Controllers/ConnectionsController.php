<?php

namespace App\Http\Controllers;

use App\Claude\ClaudeAuthenticator;
use App\Models\GithubConnection;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionsController extends Controller
{
    public function index(ClaudeAuthenticator $authenticator): Response
    {
        return Inertia::render('connections/index', [
            'claude' => $authenticator->status(),
            'github' => GithubConnection::summary(),
            'githubConfigured' => GithubConnection::configured(),
        ]);
    }
}
