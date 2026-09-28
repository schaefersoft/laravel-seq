<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use SchaeferSoft\Seq\SeqClient;

it('builds the ingestion endpoint from the server url', function (string $url, string $endpoint) {
    expect((new SeqClient(new Factory, $url))->endpoint())->toBe($endpoint);
})->with([
    ['https://seq.example.com', 'https://seq.example.com/ingest/clef'],
    ['https://seq.example.com/', 'https://seq.example.com/ingest/clef'],
    ['https://example.com/seq', 'https://example.com/seq/ingest/clef'],
]);

it('sends the payload with short timeouts', function () {
    $options = [];
    $http = (new Factory)->fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Factory::response(['MinimumLevelAccepted' => null], 201);
    });

    $response = (new SeqClient($http, 'https://seq.test', 'secret', timeout: 1.5, connectTimeout: 0.5))->send("{}\n");

    expect($response->status())->toBe(201)
        ->and($options)->toMatchArray(['timeout' => 1.5, 'connect_timeout' => 0.5]);

    $http->assertSent(fn (Request $request): bool => $request->body() === "{}\n");
});
