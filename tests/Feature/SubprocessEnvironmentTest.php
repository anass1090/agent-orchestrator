<?php

use App\Pipeline\SubprocessEnvironment;

it('gives the agent the pipeline git identity for its own commits', function () {
    config(['pipeline.commit_author' => ['name' => 'Pipeline Bot', 'email' => 'bot@example.com']]);

    $environment = app(SubprocessEnvironment::class)->for();

    expect($environment)->toMatchArray([
        'GIT_AUTHOR_NAME' => 'Pipeline Bot',
        'GIT_COMMITTER_NAME' => 'Pipeline Bot',
        'GIT_AUTHOR_EMAIL' => 'bot@example.com',
        'GIT_COMMITTER_EMAIL' => 'bot@example.com',
    ]);
});

it('leaves git to detect an identity when none is configured', function () {
    config(['pipeline.commit_author' => ['name' => null, 'email' => null]]);

    $environment = app(SubprocessEnvironment::class)->for();

    expect($environment)->not->toHaveKeys(['GIT_AUTHOR_NAME', 'GIT_COMMITTER_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_EMAIL']);
});
