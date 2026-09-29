<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use SchaeferSoft\Seq\CircuitBreaker;
use SchaeferSoft\Seq\SeqClient;

it('stays open for the cooldown after it was tripped', function () {
    $breaker = new CircuitBreaker(new Repository(new ArrayStore), 'seq', 30);

    expect($breaker->isOpen())->toBeFalse();

    $breaker->trip();

    $this->travel(29)->seconds();
    expect($breaker->isOpen())->toBeTrue();

    $this->travel(1)->seconds();
    expect($breaker->isOpen())->toBeFalse();
});

it('keeps a separate state for every seq server', function () {
    $cache = new Repository(new ArrayStore);
    $breaker = CircuitBreaker::for(new SeqClient(new Factory, 'https://seq.test'), $cache, 30);

    $breaker->trip();

    expect($breaker->isOpen())->toBeTrue()
        ->and(CircuitBreaker::for(new SeqClient(new Factory, 'https://seq.test/'), $cache, 30)->isOpen())->toBeTrue()
        ->and(CircuitBreaker::for(new SeqClient(new Factory, 'https://audit.test'), $cache, 30)->isOpen())->toBeFalse();
});

it('stays closed when the cache fails', function () {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->allows('has')->andThrow(new RuntimeException('Cache is down'));
    $cache->allows('put')->andThrow(new RuntimeException('Cache is down'));
    $breaker = new CircuitBreaker($cache, 'seq', 30);

    $breaker->trip();

    expect($breaker->isOpen())->toBeFalse();
});
