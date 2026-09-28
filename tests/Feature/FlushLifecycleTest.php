<?php

declare(strict_types=1);

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use SchaeferSoft\Seq\Facades\Seq;

it('sends events after the response instead of during the request', function () {
    $http = Seq::fake();
    $sentDuringRequest = null;

    Route::get('/checkout', function () use ($http, &$sentDuringRequest) {
        Log::channel('seq')->info('Checkout started');
        Log::channel('seq')->info('Checkout finished');

        $sentDuringRequest = $http->recorded()->count();

        return 'ok';
    });

    $this->get('/checkout')->assertOk();

    expect($sentDuringRequest)->toBe(0);
    $http->assertSentCount(1);
    expect(array_column(sentEvents($http), '@mt'))->toBe(['Checkout started', 'Checkout finished']);
});

it('sends events when the application terminates', function () {
    $http = Seq::fake();

    Log::channel('seq')->info('Command finished');

    $http->assertNothingSent();

    $this->app->terminate();

    $http->assertSentCount(1);
});

it('sends events between queued jobs without pausing the worker', function () {
    $http = Seq::fake();

    Log::channel('seq')->info('Job processed');

    expect(Event::until(new Looping('database', 'default')))->toBeNull();

    $http->assertSentCount(1);
});

it('sends events when the queue worker stops', function () {
    $http = Seq::fake();

    Log::channel('seq')->info('Worker stopping');
    event(new WorkerStopping);

    $http->assertSentCount(1);
});

it('sends events when the logger is reset', function () {
    $http = Seq::fake();

    Log::channel('seq')->info('Octane request handled');
    Log::channel('seq')->getLogger()->reset();

    $http->assertSentCount(1);
});

it('sends events of every seq channel on flush', function () {
    $http = Seq::fake();

    Log::channel('seq')->info('Default channel');
    Log::build(['driver' => 'seq', 'url' => 'https://other.test'])->info('On-demand channel');
    Seq::flush();

    $http->assertSentCount(2);
});
