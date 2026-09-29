<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use SchaeferSoft\Seq\CircuitBreaker;
use SchaeferSoft\Seq\ClefFormatter;
use SchaeferSoft\Seq\SeqHandler;

it('buffers events until it is flushed', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http);

    $handler->handle(logRecord('First'));
    $handler->handle(logRecord('Second'));

    $http->assertNothingSent();

    $handler->flush();

    $http->assertSentCount(1);
    expect(array_column(sentEvents($http), '@mt'))->toBe(['First', 'Second']);
});

it('posts newline delimited clef to the ingestion endpoint', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http);

    $handler->handle(logRecord('Hello'));
    $handler->flush();

    $http->assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://seq.test/ingest/clef'
        && $request->hasHeader('X-Seq-ApiKey', 'secret')
        && $request->hasHeader('Content-Type', 'application/vnd.serilog.clef')
        && str_ends_with($request->body(), "}\n"));
});

it('does not send an api key header without an api key', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http, apiKey: null);

    $handler->handle(logRecord('Hello'));
    $handler->flush();

    $http->assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Seq-ApiKey'));
});

it('flushes when the batch size is reached', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http, batchSize: 2);

    $handler->handle(logRecord('First'));

    $http->assertNothingSent();

    $handler->handle(logRecord('Second'));

    $http->assertSentCount(1);
});

it('flushes when the oldest buffered event exceeds the flush interval', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http, flushInterval: 5.0);

    $handler->handle(logRecord('First', datetime: new DateTimeImmutable('2026-09-28 12:00:00')));
    $handler->handle(logRecord('Second', datetime: new DateTimeImmutable('2026-09-28 12:00:04.9')));

    $http->assertNothingSent();

    $handler->handle(logRecord('Third', datetime: new DateTimeImmutable('2026-09-28 12:00:05')));

    $http->assertSentCount(1);
    expect(sentEvents($http))->toHaveCount(3);
});

it('does not flush on time when the flush interval is disabled', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http, flushInterval: 0);

    $handler->handle(logRecord('First', datetime: new DateTimeImmutable('2026-09-28 12:00:00')));
    $handler->handle(logRecord('Second', datetime: new DateTimeImmutable('2026-09-28 13:00:00')));

    $http->assertNothingSent();
});

it('splits large buffers into payloads below the request size limit', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http);

    foreach (range(1, 6) as $index) {
        $handler->handle(logRecord('Chunk {index}', ['index' => $index, 'blob' => str_repeat('x', 200_000)]));
    }

    $handler->flush();

    expect($http->recorded())->toHaveCount(2)
        ->and($http->recorded()->every(fn (array $pair): bool => strlen($pair[0]->body()) <= SeqHandler::MAX_PAYLOAD_BYTES))->toBeTrue()
        ->and(array_column(sentEvents($http), 'index'))->toBe([1, 2, 3, 4, 5, 6]);
});

it('swallows connection failures and discards the failed events', function () {
    $attempts = 0;
    $http = (new Factory)->fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Connection refused');
    });
    $handler = seqHandler($http);

    $handler->handle(logRecord('Hello'));
    $handler->flush();
    $handler->flush();

    expect($attempts)->toBe(1);
});

it('stops sending the remaining payloads after a connection failure', function () {
    $attempts = 0;
    $http = (new Factory)->fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Operation timed out');
    });
    $handler = seqHandler($http);

    foreach (range(1, 6) as $index) {
        $handler->handle(logRecord('Chunk', ['blob' => str_repeat('x', 200_000)]));
    }

    $handler->flush();

    expect($attempts)->toBe(1);
});

it('stops sending for the cooldown after a connection failure', function () {
    $attempts = 0;
    $http = (new Factory)->fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Operation timed out');
    });
    $handler = seqHandler($http, circuitBreaker: new CircuitBreaker(new Repository(new ArrayStore), 'seq', 30));

    $handler->handle(logRecord('First'));
    $handler->flush();
    $handler->handle(logRecord('Second'));
    $handler->flush();

    expect($attempts)->toBe(1);

    $this->travel(30)->seconds();
    $handler->handle(logRecord('Third'));
    $handler->flush();

    expect($attempts)->toBe(2);
});

it('does not trip the circuit breaker on error responses', function () {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()->push('Internal Server Error', 500)->push('', 201)]);
    $handler = seqHandler($http, circuitBreaker: new CircuitBreaker(new Repository(new ArrayStore), 'seq', 30));

    $handler->handle(logRecord('First'));
    $handler->flush();
    $handler->handle(logRecord('Second'));
    $handler->flush();

    expect($http->recorded())->toHaveCount(2);
});

it('keeps sending the remaining payloads after an error response', function () {
    $http = new Factory;
    $http->fake(['*' => $http->sequence()->push('Internal Server Error', 500)->push('', 201)]);
    $handler = seqHandler($http);

    foreach (range(1, 6) as $index) {
        $handler->handle(logRecord('Chunk', ['blob' => str_repeat('x', 200_000)]));
    }

    $handler->flush();

    expect($http->recorded())->toHaveCount(2);
});

it('does not flush recursively when sending produces new log events', function () {
    $http = new Factory;
    $handler = seqHandler($http);

    $http->fake(function () use ($handler) {
        $handler->handle(logRecord('Logged while sending'));

        return Factory::response(null, 201);
    });

    $handler->handle(logRecord('Hello'));
    $handler->flush();

    expect(array_column(sentEvents($http), '@mt'))->toBe(['Hello']);

    $handler->flush();

    expect(array_column(sentEvents($http), '@mt'))->toBe(['Hello', 'Logged while sending']);
});

it('flushes when the handler is closed or reset', function (string $method) {
    $http = (new Factory)->fake();
    $handler = seqHandler($http);

    $handler->handle(logRecord('Hello'));
    $handler->{$method}();

    $http->assertSentCount(1);
})->with(['close', 'reset']);

it('ignores records below the minimum level', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http, level: Level::Warning);

    $handler->handle(logRecord('Debug', level: Level::Debug));
    $handler->handle(logRecord('Warning', level: Level::Warning));
    $handler->flush();

    expect(array_column(sentEvents($http), '@mt'))->toBe(['Warning']);
});

it('lets records bubble to the next handler', function () {
    expect(seqHandler((new Factory)->fake())->handle(logRecord()))->toBeFalse();
});

it('never lets a failing processor break the application', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http);
    $handler->pushProcessor(fn () => throw new RuntimeException('Processor failed'));

    expect($handler->handle(logRecord()))->toBeFalse();

    $handler->flush();

    $http->assertNothingSent();
});

it('formats records as clef by default', function () {
    expect(seqHandler(new Factory)->getFormatter())->toBeInstanceOf(ClefFormatter::class);
});

it('encodes the output of custom formatters that do not return strings', function () {
    $http = (new Factory)->fake();
    $handler = seqHandler($http);
    $handler->setFormatter(new NormalizerFormatter);

    $handler->handle(logRecord('Custom'));
    $handler->flush();

    expect(sentEvents($http)[0])->toMatchArray(['message' => 'Custom', 'level_name' => 'INFO']);
});
