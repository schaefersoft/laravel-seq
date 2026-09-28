<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use SchaeferSoft\Seq\Facades\Seq;

it('sends a test event', function () {
    $http = Seq::fake();

    $this->artisan('seq:test')
        ->expectsOutputToContain('Seq accepted the test event')
        ->assertSuccessful();

    expect(sentEvents($http))->sequence(fn ($event) => $event->toMatchArray([
        '@mt' => 'Seq connection test via the {Channel} log channel',
        '@l' => 'Information',
        'Channel' => 'seq',
        'Application' => 'Testing',
    ]));
});

it('tests the settings of another seq channel', function () {
    config()->set('logging.channels.audit', ['driver' => 'seq', 'url' => 'https://audit.test']);
    $http = Seq::fake();

    $this->artisan('seq:test', ['--channel' => 'audit'])->assertSuccessful();

    expect($http->recorded()->first()[0]->url())->toBe('https://audit.test/ingest/clef');
});

it('explains rejected test events', function (int $status, string $hint) {
    Seq::fake(fn () => Factory::response(['Error' => 'Rejected by Seq.'], $status));

    $this->artisan('seq:test')
        ->expectsOutputToContain('Rejected by Seq.')
        ->expectsOutputToContain($hint)
        ->assertFailed();
})->with([
    'unauthorized' => [401, 'SEQ_API_KEY'],
    'forbidden' => [403, 'SEQ_API_KEY'],
    'not found' => [404, 'SEQ_URL'],
]);

it('reports connection failures', function () {
    Seq::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('seq:test')
        ->expectsOutputToContain('Could not reach Seq')
        ->assertFailed();
});

it('fails for channels that do not use the seq driver', function () {
    $this->artisan('seq:test', ['--channel' => 'single'])
        ->expectsOutputToContain('does not use the seq driver')
        ->assertFailed();
});

it('fails with an invalid configuration', function () {
    config()->set('seq.level', 'verbose');

    $this->artisan('seq:test')
        ->expectsOutputToContain('Invalid Seq log level')
        ->assertFailed();
});

it('fails without a seq url', function () {
    config()->set('seq.url', null);

    $this->artisan('seq:test')
        ->expectsOutputToContain('No Seq URL is configured')
        ->assertFailed();
});

it('sends the test event even when shipping is disabled', function () {
    config()->set('seq.enabled', false);
    $http = Seq::fake();

    $this->artisan('seq:test')
        ->expectsOutputToContain('Seq logging is disabled')
        ->assertSuccessful();

    $http->assertSentCount(1);
});
