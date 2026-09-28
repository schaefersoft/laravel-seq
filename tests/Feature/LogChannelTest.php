<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NoopHandler;
use Monolog\Handler\TestHandler;
use Monolog\Processor\UidProcessor;
use SchaeferSoft\Seq\Facades\Seq;
use SchaeferSoft\Seq\SeqHandler;
use SchaeferSoft\Seq\SeqLoggerFactory;
use SchaeferSoft\Seq\SeqServiceProvider;

it('merges the package configuration', function () {
    expect(config('seq'))->toHaveKeys([
        'enabled', 'url', 'api_key', 'level', 'timeout', 'connect_timeout',
        'batch_size', 'flush_interval', 'max_event_size', 'properties',
    ]);
});

it('registers a seq log channel', function () {
    expect(config('logging.channels.seq'))->toBe(['driver' => 'seq'])
        ->and(Log::channel('seq')->getLogger()->getHandlers())->sequence(
            fn ($handler) => $handler->toBeInstanceOf(SeqHandler::class),
        );
});

it('keeps a seq channel defined by the application', function () {
    config()->set('logging.channels.seq', ['driver' => 'seq', 'level' => 'error']);

    (new SeqServiceProvider(app()))->register();

    expect(config('logging.channels.seq'))->toBe(['driver' => 'seq', 'level' => 'error']);
});

it('ships structured events to seq', function () {
    $http = Seq::fake();

    Log::channel('seq')->info('Order {order_id} placed', ['order_id' => 7]);
    Seq::flush();

    $http->assertSent(fn (Request $request): bool => $request->url() === 'https://seq.test/ingest/clef'
        && $request->hasHeader('X-Seq-ApiKey', 'test-api-key'));

    expect(sentEvents($http))->sequence(fn ($event) => $event->toMatchArray([
        '@mt' => 'Order {order_id} placed',
        '@l' => 'Information',
        'order_id' => 7,
        'Application' => 'Testing',
    ]));
});

it('reports exceptions with their stack trace', function () {
    config()->set('logging.default', 'seq');
    $http = Seq::fake();

    report(new RuntimeException('Payment provider unavailable'));
    Seq::flush();

    $event = sentEvents($http)[0];

    expect($event['@l'])->toBe('Error')
        ->and($event['@mt'])->toBe('Payment provider unavailable')
        ->and($event['@x'])->toStartWith('RuntimeException: Payment provider unavailable in ');
});

it('includes shared log context', function () {
    $http = Seq::fake();

    Log::shareContext(['request_id' => 'req-1']);
    Log::channel('seq')->info('Hello');
    Seq::flush();

    expect(sentEvents($http)[0])->toMatchArray(['request_id' => 'req-1']);
});

it('includes data from the context repository', function () {
    $http = Seq::fake();

    Context::add('trace_id', 'trace-1');
    Log::channel('seq')->info('Hello');
    Seq::flush();

    expect(sentEvents($http)[0])->toMatchArray(['trace_id' => 'trace-1']);
});

it('respects the configured level', function () {
    config()->set('seq.level', 'warning');
    $http = Seq::fake();

    Log::channel('seq')->info('Ignored');
    Log::channel('seq')->warning('Shipped');
    Seq::flush();

    expect(array_column(sentEvents($http), '@mt'))->toBe(['Shipped']);
});

it('lets the channel configuration override the package configuration', function () {
    $http = Seq::fake();

    Log::build([
        'driver' => 'seq',
        'url' => 'https://logs.example.com',
        'api_key' => 'channel-key',
        'properties' => ['Application' => 'Billing'],
    ])->info('Invoice sent');
    Seq::flush();

    $http->assertSent(fn (Request $request): bool => $request->url() === 'https://logs.example.com/ingest/clef'
        && $request->hasHeader('X-Seq-ApiKey', 'channel-key'));

    expect(sentEvents($http)[0])->toMatchArray(['Application' => 'Billing']);
});

it('applies configured processors', function () {
    $http = Seq::fake();

    Log::build([
        'driver' => 'seq',
        'processors' => [['processor' => UidProcessor::class, 'with' => ['length' => 12]]],
    ])->info('With uid');
    Seq::flush();

    expect(sentEvents($http)[0]['uid'])->toBeString()->toHaveLength(12);
});

it('rejects processors that are not invokable', function () {
    app(SeqLoggerFactory::class)(['processors' => [stdClass::class]]);
})->throws(InvalidArgumentException::class, 'Seq log processors must be invokable, [stdClass] given.');

it('rejects a processors option that is not a list', function () {
    app(SeqLoggerFactory::class)(['processors' => UidProcessor::class]);
})->throws(InvalidArgumentException::class, 'The Seq channel "processors" option must be an array.');

it('does not ship anything when seq is disabled', function (array $config) {
    config()->set('seq', [...config('seq'), ...$config]);

    expect(Log::channel('seq')->getLogger()->getHandlers())->sequence(
        fn ($handler) => $handler->toBeInstanceOf(NoopHandler::class),
    );
})->with([
    'disabled' => [['enabled' => false]],
    'without url' => [['url' => null]],
]);

it('keeps the other channels of a stack working', function (array $config) {
    config()->set('seq', [...config('seq'), ...$config]);
    config()->set('logging.channels.memory', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    Seq::fake(fn () => throw new ConnectionException('Seq is down'));

    Log::stack(['seq', 'memory'])->error('Still logged');
    Seq::flush();

    /** @var TestHandler $memory */
    $memory = Log::channel('memory')->getLogger()->getHandlers()[0];

    expect($memory->hasErrorThatContains('Still logged'))->toBeTrue();
})->with([
    'seq unreachable' => [[]],
    'seq disabled' => [['enabled' => false]],
]);
