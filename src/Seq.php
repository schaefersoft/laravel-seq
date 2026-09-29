<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Cache\ApcStore;
use Illuminate\Cache\ApcWrapper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use WeakMap;

final class Seq
{
    private readonly Factory $http;

    private ?Repository $circuitBreakerCache = null;

    /** @var WeakMap<SeqHandler, true> */
    private readonly WeakMap $handlers;

    public function __construct(?Factory $http = null)
    {
        $this->http = $http ?? new Factory;
        $this->handlers = new WeakMap;
    }

    public function http(): Factory
    {
        return $this->http;
    }

    public function circuitBreakerCache(): Repository
    {
        return $this->circuitBreakerCache ??= new CacheRepository(
            function_exists('apcu_enabled') && apcu_enabled() ? new ApcStore(new ApcWrapper) : new ArrayStore,
        );
    }

    public function track(SeqHandler $handler): void
    {
        $this->handlers[$handler] = true;
    }

    public function flush(): void
    {
        foreach ($this->handlers as $handler => $tracked) {
            $handler->flush();
        }
    }

    /**
     * @param  callable|array<string, mixed>|null  $responses
     */
    public function fake(callable|array|null $responses = null): Factory
    {
        return $this->http->fake($responses ?? static fn () => Factory::response(status: 201));
    }
}
