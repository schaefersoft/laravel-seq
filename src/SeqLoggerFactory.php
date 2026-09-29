<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Monolog\Handler\NoopHandler;
use Monolog\Logger;

final class SeqLoggerFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly Seq $seq,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $defaults = $this->config->get('seq', []);
        $settings = SeqConfig::fromArray(array_replace(is_array($defaults) ? $defaults : [], $config));
        $logger = new Logger(is_string($config['name'] ?? null) ? $config['name'] : 'seq');

        if (! $settings->isActive()) {
            return $logger->pushHandler(new NoopHandler);
        }

        $client = $settings->client($this->seq->http());

        $handler = new SeqHandler(
            $client,
            $settings->level,
            $settings->bubble,
            $settings->batchSize,
            $settings->flushInterval,
            $settings->circuitBreaker > 0
                ? CircuitBreaker::for($client, $this->circuitBreakerCache($settings), $settings->circuitBreaker)
                : null,
        );

        $handler->setFormatter($settings->formatter());

        foreach ($this->processors($config['processors'] ?? []) as $processor) {
            $handler->pushProcessor($processor);
        }

        $this->seq->track($handler);

        return $logger->pushHandler($handler);
    }

    private function circuitBreakerCache(SeqConfig $settings): CacheRepository
    {
        return $settings->circuitBreakerStore === null
            ? $this->seq->circuitBreakerCache()
            : $this->container->make(CacheFactory::class)->store($settings->circuitBreakerStore);
    }

    /**
     * @return list<callable>
     */
    private function processors(mixed $processors): array
    {
        if (! is_array($processors)) {
            throw new InvalidArgumentException('The Seq channel "processors" option must be an array.');
        }

        $resolved = [];

        foreach ($processors as $processor) {
            $class = is_array($processor) ? ($processor['processor'] ?? null) : $processor;
            $with = is_array($processor) && is_array($processor['with'] ?? null) ? $processor['with'] : [];
            $instance = is_string($class) ? $this->container->make($class, $with) : $class;

            if (! is_callable($instance)) {
                throw new InvalidArgumentException('Seq log processors must be invokable, ['.get_debug_type($instance).'] given.');
            }

            $resolved[] = $instance;
        }

        return $resolved;
    }
}
