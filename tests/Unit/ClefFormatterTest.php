<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Monolog\Level;
use SchaeferSoft\Seq\ClefFormatter;
use SchaeferSoft\Seq\Tests\Fixtures\Customer;
use SchaeferSoft\Seq\Tests\Fixtures\EmailAddress;
use SchaeferSoft\Seq\Tests\Fixtures\ExplodingValue;
use SchaeferSoft\Seq\Tests\Fixtures\Money;
use SchaeferSoft\Seq\Tests\Fixtures\OrderStatus;
use SchaeferSoft\Seq\Tests\Fixtures\Priority;
use SchaeferSoft\Seq\Tests\Fixtures\Tags;

it('writes the timestamp in UTC with microsecond precision', function () {
    expect(clef(logRecord()))->toMatchArray(['@t' => '2026-09-28T10:34:56.123456Z']);
});

it('uses the log message as message template', function () {
    $event = clef(logRecord('User {user_id} signed in from {ip}', ['user_id' => 42, 'ip' => '10.0.0.1']));

    expect($event)->toMatchArray([
        '@mt' => 'User {user_id} signed in from {ip}',
        'user_id' => 42,
        'ip' => '10.0.0.1',
    ]);
});

it('maps monolog levels to seq levels', function (Level $level, string $expected) {
    expect(clef(logRecord(level: $level))['@l'])->toBe($expected);
})->with([
    [Level::Debug, 'Debug'],
    [Level::Info, 'Information'],
    [Level::Notice, 'Information'],
    [Level::Warning, 'Warning'],
    [Level::Error, 'Error'],
    [Level::Critical, 'Fatal'],
    [Level::Alert, 'Fatal'],
    [Level::Emergency, 'Fatal'],
]);

it('escapes braces that are not placeholders', function (string $message, string $template) {
    expect(clef(logRecord($message))['@mt'])->toBe($template);
})->with([
    'blade echo' => ['Blade {{ $user->name }} failed', 'Blade {{{{ $user->name }}}} failed'],
    'json payload' => ['Payload {"id":1}', 'Payload {{"id":1}}'],
    'unbalanced brace' => ['Unclosed { brace', 'Unclosed {{ brace'],
    'placeholder in double braces' => ['{{order_id}}', '{{{order_id}}}'],
]);

it('keeps message template tokens intact', function (string $message) {
    expect(clef(logRecord($message))['@mt'])->toBe($message);
})->with([
    'placeholder' => ['Order {order_id} shipped'],
    'dotted placeholder' => ['Hello {user.name}'],
    'destructuring' => ['Created {@order}'],
    'stringification' => ['Created {$order}'],
    'format and alignment' => ['Total {amount:0.00} for {name,-10}'],
    'positional' => ['{0} and {1}'],
    'unicode name' => ['Größe {größe}'],
    'plain text' => ['Nothing to see here'],
]);

it('renders exceptions into the exception field', function () {
    $event = clef(logRecord('Checkout failed', [
        'exception' => new RuntimeException('Payment failed'),
        'order_id' => 7,
    ]));

    expect($event['@x'])
        ->toStartWith('RuntimeException: Payment failed in '.__FILE__.':')
        ->toContain("\nStack trace:\n#0 ")
        ->and($event)->not->toHaveKey('exception')
        ->and($event['order_id'])->toBe(7);
});

it('renders the chain of previous exceptions', function () {
    $exception = new RuntimeException('Checkout failed', 0, new LogicException('Card declined', 402));

    expect(clef(logRecord('Failed', ['exception' => $exception]))['@x'])
        ->toStartWith('RuntimeException: Checkout failed in ')
        ->toContain("\n\nCaused by: LogicException(code: 402): Card declined in ");
});

it('keeps an exception entry that is not a throwable as property', function () {
    $event = clef(logRecord('Failed', ['exception' => 'Something broke']));

    expect($event)->not->toHaveKey('@x')
        ->and($event['exception'])->toBe('Something broke');
});

it('merges static properties, extra and context with the most specific value winning', function () {
    $event = clef(
        logRecord(
            'Hello',
            context: ['Environment' => 'context', 'request_id' => 'req-1'],
            extra: ['Environment' => 'extra', 'trace_id' => 'trace-1'],
        ),
        ['Application' => 'Shop', 'Environment' => 'production'],
    );

    expect($event)->toMatchArray([
        'Application' => 'Shop',
        'Environment' => 'context',
        'request_id' => 'req-1',
        'trace_id' => 'trace-1',
    ]);
});

it('escapes property names that start with an at sign', function () {
    $event = clef(logRecord('Hello', ['@t' => 'spoofed', '@user' => 'ada']));

    expect($event)->toMatchArray([
        '@t' => '2026-09-28T10:34:56.123456Z',
        '@@t' => 'spoofed',
        '@@user' => 'ada',
    ]);
});

it('keeps positional context values', function () {
    expect(clef(logRecord('{0} and {1}', ['first', 'second'])))->toMatchArray([0 => 'first', 1 => 'second']);
});

it('normalizes context values into structured properties', function () {
    $event = clef(logRecord('Hello', [
        'date' => new DateTimeImmutable('2026-01-02 03:04:05.678901', new DateTimeZone('UTC')),
        'status' => OrderStatus::Paid,
        'priority' => Priority::High,
        'money' => new Money(1000, 'CHF'),
        'tags' => new Tags(['vip', 'b2b']),
        'customer' => new Customer(1, 'Ada'),
        'email' => new EmailAddress('ada@example.com'),
        'collection' => new Collection(['a' => 1]),
        'list' => new Collection([1, 2]),
        'empty' => new Collection,
        'callback' => fn () => null,
        'resource' => fopen('php://memory', 'r'),
        'infinity' => INF,
        'nothing' => null,
        'nested' => ['customer' => new Customer(2, 'Grace')],
    ]));

    expect($event)->toMatchArray([
        'date' => '2026-01-02T03:04:05.678901+00:00',
        'status' => 'paid',
        'priority' => 'High',
        'money' => ['$type' => Money::class, 'amount' => 1000, 'currency' => 'CHF'],
        'tags' => ['vip', 'b2b'],
        'customer' => ['$type' => Customer::class, 'id' => 1, 'name' => 'Ada'],
        'email' => 'ada@example.com',
        'collection' => ['$type' => Collection::class, 'a' => 1],
        'list' => [1, 2],
        'empty' => [],
        'callback' => ['$type' => 'Closure'],
        'resource' => '[resource(stream)]',
        'infinity' => 'INF',
        'nothing' => null,
        'nested' => ['customer' => ['$type' => Customer::class, 'id' => 2, 'name' => 'Grace']],
    ]);
});

it('replaces values that cannot be serialized', function () {
    expect(clef(logRecord('Hello', ['value' => new ExplodingValue])))
        ->toMatchArray(['value' => '[unserializable '.ExplodingValue::class.': Cannot serialize]']);
});

it('substitutes invalid utf-8 instead of dropping the event', function () {
    $json = (new ClefFormatter)->format(logRecord("Broken \xB1 text", ['value' => "bad \xC3\x28"]));

    expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
        '@mt' => "Broken \u{FFFD} text",
        'value' => "bad \u{FFFD}(",
    ]);
});

it('drops the largest properties when an event exceeds the size limit', function () {
    $json = (new ClefFormatter(['Application' => 'Shop'], 4096))->format(logRecord('Import {batch} finished', [
        'batch' => 7,
        'payload' => str_repeat('x', 5000),
        'rows' => str_repeat('y', 3000),
    ]));

    expect(strlen($json))->toBeLessThanOrEqual(4096)
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            '@mt' => 'Import {batch} finished',
            'Application' => 'Shop',
            'batch' => 7,
            'rows' => str_repeat('y', 3000),
            'EventBodyLimitBytes' => 4096,
            'DroppedProperties' => ['payload'],
        ])
        ->not->toHaveKey('payload');
});

it('truncates long messages when dropping properties is not enough', function () {
    $json = (new ClefFormatter([], 65536))->format(logRecord(str_repeat('a', 70000), [
        'exception' => new RuntimeException(str_repeat('b', 70000)),
    ]));

    expect(strlen($json))->toBeLessThanOrEqual(65536)
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            '@mt' => str_repeat('a', 1024).'…',
            'EventBodyLimitBytes' => 65536,
        ])
        ->{'@x'}->toStartWith('RuntimeException: '.str_repeat('b', 1024).'… in ');
});

it('falls back to a placeholder event when the event cannot be shrunk', function () {
    $json = (new ClefFormatter(['Application' => 'Shop'], 4096))->format(
        logRecord('Recursion {depth}', ['depth' => 300, 'exception' => deepException(300)], Level::Error),
    );

    $event = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect(strlen($json))->toBeLessThanOrEqual(4096)
        ->and($event)->toMatchArray([
            '@t' => '2026-09-28T10:34:56.123456Z',
            '@mt' => 'Event JSON representation exceeds the body size limit {EventBodyLimitBytes}; sample: {EventBodySample}',
            '@l' => 'Error',
            'Application' => 'Shop',
            'EventBodyLimitBytes' => 4096,
        ])
        ->and($event['EventBodySample'])->toStartWith('{"@t":"2026-09-28T10:34:56.123456Z","@mt":"Recursion {depth}"');
});

it('keeps events within the size limit for any content', function (string $value) {
    $json = (new ClefFormatter([], 4096))->format(logRecord('Hello', ['value' => $value]));

    expect(strlen($json))->toBeLessThanOrEqual(4096)
        ->and(json_decode($json, true))->toBeArray();
})->with([
    'multibyte' => [str_repeat('ü', 5000)],
    'control characters' => [str_repeat("\x01", 2000)],
    'quotes' => [str_repeat('"', 5000)],
]);
