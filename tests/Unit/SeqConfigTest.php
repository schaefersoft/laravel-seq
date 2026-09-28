<?php

declare(strict_types=1);

use Monolog\Level;
use SchaeferSoft\Seq\SeqConfig;

it('parses values coming from the environment', function () {
    $config = SeqConfig::fromArray([
        'enabled' => 'false',
        'url' => ' https://seq.test ',
        'api_key' => '',
        'level' => 'WARNING',
        'bubble' => 'off',
        'timeout' => '1.5',
        'connect_timeout' => '0.5',
        'batch_size' => '50',
        'flush_interval' => '0',
        'max_event_size' => '131072',
        'properties' => ['Application' => 'Shop', 'Region' => null],
    ]);

    expect($config)->toMatchObject([
        'enabled' => false,
        'url' => 'https://seq.test',
        'apiKey' => null,
        'level' => Level::Warning,
        'bubble' => false,
        'timeout' => 1.5,
        'connectTimeout' => 0.5,
        'batchSize' => 50,
        'flushInterval' => 0.0,
        'maxEventSize' => 131072,
        'properties' => ['Application' => 'Shop'],
    ]);
});

it('falls back to defaults for missing values', function () {
    expect(SeqConfig::fromArray([]))->toMatchObject([
        'enabled' => true,
        'url' => null,
        'apiKey' => null,
        'level' => Level::Debug,
        'bubble' => true,
        'timeout' => 2.0,
        'connectTimeout' => 1.0,
        'batchSize' => 100,
        'flushInterval' => 5.0,
        'maxEventSize' => 262144,
        'properties' => [],
    ]);
});

it('accepts level names, numbers and enums', function (mixed $level, Level $expected) {
    expect(SeqConfig::fromArray(['level' => $level])->level)->toBe($expected);
})->with([
    ['notice', Level::Notice],
    ['Critical', Level::Critical],
    [200, Level::Info],
    ['400', Level::Error],
    [Level::Alert, Level::Alert],
    ['', Level::Debug],
]);

it('rejects unknown levels', function () {
    SeqConfig::fromArray(['level' => 'verbose']);
})->throws(InvalidArgumentException::class, 'Invalid Seq log level [verbose]');

it('is only active when enabled and a url is configured', function (array $config, bool $active) {
    expect(SeqConfig::fromArray($config)->isActive())->toBe($active);
})->with([
    [['url' => 'https://seq.test'], true],
    [['url' => 'https://seq.test', 'enabled' => false], false],
    [['url' => ''], false],
    [[], false],
]);
