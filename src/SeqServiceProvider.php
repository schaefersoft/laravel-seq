<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Monolog\Logger;
use SchaeferSoft\Seq\Commands\TestConnectionCommand;

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
        $this->app->terminating(function (): void {
            $this->app->make(Seq::class)->flush();
        });

        $this->app->make(Dispatcher::class)->listen([Looping::class, WorkerStopping::class], function (): void {
            $this->app->make(Seq::class)->flush();
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/seq.php' => $this->app->configPath('seq.php'),
            ], 'seq-config');

            $this->commands([
                TestConnectionCommand::class,
            ]);

            $this->registerAboutSection();
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

    private function registerAboutSection(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Seq', static function (Repository $config): array {
            $values = $config->get('seq', []);

            try {
                $settings = SeqConfig::fromArray(is_array($values) ? $values : []);
            } catch (InvalidArgumentException $e) {
                return ['Configuration' => $e->getMessage()];
            }

            return [
                'Enabled' => $settings->isActive() ? 'ENABLED' : 'DISABLED',
                'URL' => $settings->url ?? '-',
                'API Key' => $settings->apiKey !== null ? 'SET' : 'NOT SET',
                'Level' => $settings->level->getName(),
            ];
        });
    }
}
