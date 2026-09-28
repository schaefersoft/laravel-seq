<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SchaeferSoft\Seq\Seq;
use SchaeferSoft\Seq\SeqServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Seq::class)->http()->preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SeqServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('seq.url', 'https://seq.test');
        $app['config']->set('seq.api_key', 'test-api-key');
        $app['config']->set('seq.properties', ['Application' => 'Testing']);
    }
}
