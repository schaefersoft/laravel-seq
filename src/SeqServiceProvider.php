<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;

final class SeqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/seq.php', 'seq');

        $this->app->instance(Seq::class, new Seq);

        $this->registerLogChannel();

        $this->callAfterResolving('log', function (LogManager $log): void {
            $log->extend('seq', function (Container $app, array $config): Logger {
                return $app->make(SeqLoggerFactory::class)($config);
            });
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/seq.php' => $this->app->configPath('seq.php'),
            ], 'seq-config');
        }
    }

    private function registerLogChannel(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make(Repository::class);

        if (! $config->has('logging.channels.seq')) {
            $config->set('logging.channels.seq', ['driver' => 'seq']);
        }
    }
}
