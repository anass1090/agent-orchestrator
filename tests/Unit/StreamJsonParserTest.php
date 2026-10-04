<?php

use App\Pipeline\StreamJsonParser;

it('reassembles lines split across output chunks', function () {
    $parser = new StreamJsonParser;

    expect($parser->feed('{"type":"sys'))->toBe([])
        ->and($parser->feed("tem\",\"a\":1}\n{\"type\":\"result\"}\n{\"type\""))->toBe([
            ['type' => 'system', 'a' => 1],
            ['type' => 'result'],
        ])
        ->and($parser->flush())->toBe([['type' => 'stream.invalid', 'raw' => '{"type"']]);
});

it('marks non-json lines as invalid instead of dropping them', function () {
    expect((new StreamJsonParser)->feed("Error: rate limited\n\n"))
        ->toBe([['type' => 'stream.invalid', 'raw' => 'Error: rate limited']]);
});
