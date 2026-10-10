<?php

namespace App\Http\Controllers;

use App\Models\GithubConnection;
use Illuminate\Http\RedirectResponse;

class GithubDisconnectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        GithubConnection::current()?->disconnect();

        return to_route('connections.index')->with('status', 'GitHub-koppeling verwijderd.');
    }
}
