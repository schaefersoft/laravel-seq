<?php

declare(strict_types=1);

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
