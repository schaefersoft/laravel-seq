<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Monolog\Level;
use Monolog\LogRecord;
use SchaeferSoft\Seq\ClefFormatter;
use SchaeferSoft\Seq\SeqClient;
use SchaeferSoft\Seq\SeqHandler;
use SchaeferSoft\Seq\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Integration');

/**
 * @param  array<array-key, mixed>  $context
 * @param  array<array-key, mixed>  $extra
 */
function logRecord(
    string $message = 'Hello',
    array $context = [],
    Level $level = Level::Info,
    array $extra = [],
    ?DateTimeImmutable $datetime = null,
): LogRecord {
    return new LogRecord(
        datetime: $datetime ?? new DateTimeImmutable('2026-09-28 12:34:56.123456', new DateTimeZone('Europe/Zurich')),
        channel: 'testing',
        level: $level,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

/**
 * @param  array<array-key, mixed>  $properties
 * @return array<string, mixed>
 */
function clef(LogRecord $record, array $properties = [], int $maxEventSize = ClefFormatter::DEFAULT_MAX_EVENT_SIZE): array
{
    return json_decode((new ClefFormatter($properties, $maxEventSize))->format($record), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<array<string, mixed>>
 */
function sentEvents(Factory $http): array
{
    return $http->recorded()
        ->flatMap(fn (array $pair): array => payloadEvents($pair[0]))
        ->values()
        ->all();
}

/**
 * @return list<array<string, mixed>>
 */
function payloadEvents(Request $request): array
{
    return array_map(
        fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $request->body()))),
    );
}

function seqHandler(
    Factory $http,
    ?string $apiKey = 'secret',
    Level $level = Level::Debug,
    int $batchSize = 100,
    float $flushInterval = 5.0,
): SeqHandler {
    return new SeqHandler(
        new SeqClient($http, 'https://seq.test', $apiKey),
        $level,
        batchSize: $batchSize,
        flushInterval: $flushInterval,
    );
}

/**
 * @return list<array<string, mixed>>
 */
function seqEvents(string $url, string $runId, int $expected = 1): array
{
    $deadline = microtime(true) + 15;

    do {
        $events = (new Factory)->get($url.'/api/events', [
            'filter' => "RunId = '{$runId}'",
            'count' => 1000,
            'render' => 'true',
        ])->json();

        if (count($events) >= $expected) {
            return $events;
        }

        usleep(250_000);
    } while (microtime(true) < $deadline);

    return $events;
}

/**
 * @return array<string, mixed>
 */
function seqEvent(string $url, string $runId): array
{
    $events = seqEvents($url, $runId);

    expect($events)->toHaveCount(1);

    return $events[0];
}

function seqCount(string $url, string $runId, int $expected): int
{
    $deadline = microtime(true) + 15;

    do {
        $count = (new Factory)->get($url.'/api/data', [
            'q' => "select count(*) from stream where RunId = '{$runId}'",
        ])->json('Rows.0.0') ?? 0;

        if ($count >= $expected) {
            return $count;
        }

        usleep(250_000);
    } while (microtime(true) < $deadline);

    return $count;
}

/**
 * @param  array<string, mixed>  $event
 * @return array<string, mixed>
 */
function seqProperties(array $event): array
{
    return array_column($event['Properties'], 'Value', 'Name');
}

function deepException(int $depth): RuntimeException
{
    return $depth === 0 ? new RuntimeException('Deep failure') : deepException($depth - 1);
}
