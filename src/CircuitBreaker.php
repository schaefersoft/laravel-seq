<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

final class CircuitBreaker
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $key,
        private readonly int $cooldown,
    ) {}

    public static function for(SeqClient $client, Repository $cache, int $cooldown): self
    {
        return new self($cache, 'seq-circuit-breaker:'.sha1($client->endpoint()), $cooldown);
    }

    public function isOpen(): bool
    {
        try {
            return $this->cache->has($this->key);
        } catch (Throwable) {
            return false;
        }
    }

    public function trip(): void
    {
        try {
            $this->cache->put($this->key, true, $this->cooldown);
        } catch (Throwable) {
        }
    }
}
