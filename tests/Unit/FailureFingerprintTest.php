<?php

use App\Pipeline\FailureFingerprint;

it('ignores timings, memory, colours and the worktree path', function () {
    $fingerprint = new FailureFingerprint;

    $first = "\e[31mFAILED\e[0m Tests\\Feature\\InvoiceTest > it totals\n/wt/one/tests/Feature/InvoiceTest.php:12\nTests: 1 failed (0.42s)\nMemory: 24.00 MB";
    $second = "FAILED Tests\\Feature\\InvoiceTest > it totals\n/wt/two/tests/Feature/InvoiceTest.php:12\nTests: 1 failed (1.87s)\nMemory: 26.50 MB";

    expect($fingerprint->make('tests', $first, '/wt/one'))->toBe($fingerprint->make('tests', $second, '/wt/two'));
});

it('distinguishes different failures', function () {
    $fingerprint = new FailureFingerprint;

    expect($fingerprint->make('tests', 'InvoiceTest.php:12 failed'))
        ->not->toBe($fingerprint->make('tests', 'InvoiceTest.php:40 failed'))
        ->and($fingerprint->make('pint', 'x'))->not->toBe($fingerprint->make('tests', 'x'));
});
