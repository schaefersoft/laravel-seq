<?php

declare(strict_types=1);

use Illuminate\Foundation\Console\AboutCommand;

beforeEach(function () {
    if (! method_exists(AboutCommand::class, 'flushState')) {
        $this->markTestSkipped('The about command of this Laravel version keeps state between tests.');
    }
});

it('shows the seq configuration in the about command', function () {
    $this->artisan('about', ['--only' => 'seq'])
        ->expectsOutputToContain('ENABLED')
        ->expectsOutputToContain('https://seq.test')
        ->assertSuccessful();
});

it('shows invalid seq configuration in the about command', function () {
    config()->set('seq.level', 'verbose');

    $this->artisan('about', ['--only' => 'seq'])
        ->expectsOutputToContain('Invalid Seq log level')
        ->assertSuccessful();
});
