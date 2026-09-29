<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use LogicException;
use Monolog\Level;

final class SeqConfig
{
    /**
     * @param  array<array-key, mixed>  $properties
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly ?string $url = null,
        public readonly ?string $apiKey = null,
        public readonly Level $level = Level::Debug,
        public readonly bool $bubble = true,
        public readonly float $timeout = 2.0,
        public readonly float $connectTimeout = 1.0,
        public readonly int $batchSize = 100,
        public readonly float $flushInterval = 5.0,
        public readonly int $maxEventSize = ClefFormatter::DEFAULT_MAX_EVENT_SIZE,
        public readonly int $circuitBreaker = 30,
        public readonly ?string $circuitBreakerStore = null,
        public readonly array $properties = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $properties = $config['properties'] ?? null;

        return new self(
            enabled: self::boolean($config['enabled'] ?? null, true),
            url: self::string($config['url'] ?? null),
            apiKey: self::string($config['api_key'] ?? null),
            level: self::level($config['level'] ?? null),
            bubble: self::boolean($config['bubble'] ?? null, true),
            timeout: self::float($config['timeout'] ?? null, 2.0),
            connectTimeout: self::float($config['connect_timeout'] ?? null, 1.0),
            batchSize: self::integer($config['batch_size'] ?? null, 100),
            flushInterval: self::float($config['flush_interval'] ?? null, 5.0),
            maxEventSize: self::integer($config['max_event_size'] ?? null, ClefFormatter::DEFAULT_MAX_EVENT_SIZE),
            circuitBreaker: max(0, self::integer($config['circuit_breaker'] ?? null, 30)),
            circuitBreakerStore: self::string($config['circuit_breaker_store'] ?? null),
            properties: is_array($properties) ? array_filter($properties, fn (mixed $value): bool => $value !== null) : [],
        );
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->url !== null;
    }

    public function client(Factory $http): SeqClient
    {
        return new SeqClient(
            $http,
            $this->url ?? throw new LogicException('No Seq URL is configured.'),
            $this->apiKey,
            $this->timeout,
            $this->connectTimeout,
        );
    }

    public function formatter(): ClefFormatter
    {
        return new ClefFormatter($this->properties, $this->maxEventSize);
    }

    private static function level(mixed $value): Level
    {
        if ($value instanceof Level) {
            return $value;
        }

        if ($value === null || $value === '') {
            return Level::Debug;
        }

        foreach (Level::cases() as $level) {
            if (is_numeric($value) ? (int) $value === $level->value : is_string($value) && strcasecmp($value, $level->name) === 0) {
                return $level;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid Seq log level [%s]. Use one of: %s.',
            is_scalar($value) ? $value : get_debug_type($value),
            implode(', ', array_map(fn (Level $level): string => strtolower($level->name), Level::cases())),
        ));
    }

    private static function boolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function float(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private static function integer(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
