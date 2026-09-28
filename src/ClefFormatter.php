<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use __PHP_Incomplete_Class;
use BackedEnum;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Monolog\Utils;
use Stringable;
use Throwable;
use UnitEnum;

final class ClefFormatter extends NormalizerFormatter
{
    public const DEFAULT_MAX_EVENT_SIZE = 262144;

    private const MIN_EVENT_SIZE = 4096;

    private const TRUNCATED_MESSAGE_BYTES = 1024;

    private const SAMPLE_BYTES = 1024;

    private const TOKEN = '/\{[@$]?[\p{L}\p{N}_.]+(?:,-?\d+)?(?::[^{}]*)?\}|[{}]/u';

    private const PLACEHOLDER = 'Event JSON representation exceeds the body size limit {EventBodyLimitBytes}; sample: {EventBodySample}';

    private readonly int $maxEventSize;

    /** @var array<array-key, mixed> */
    private readonly array $staticProperties;

    /**
     * @param  array<array-key, mixed>  $properties
     */
    public function __construct(array $properties = [], int $maxEventSize = self::DEFAULT_MAX_EVENT_SIZE)
    {
        parent::__construct('Y-m-d\TH:i:s.uP');

        $this->maxEventSize = max(self::MIN_EVENT_SIZE, $maxEventSize);
        $this->staticProperties = $this->properties($properties);
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            unset($context['exception']);
        } else {
            $exception = null;
        }

        $properties = $this->properties(array_replace($record->extra, $context));

        $json = $this->event($record, $exception, array_replace($this->staticProperties, $properties));

        if (strlen($json) <= $this->maxEventSize) {
            return $json;
        }

        return $this->shrink($record, $exception, $properties, $json);
    }

    protected function normalize(mixed $data, int $depth = 0): mixed
    {
        if (! is_object($data)
            || $depth > $this->maxNormalizeDepth
            || $data instanceof Throwable
            || $data instanceof DateTimeInterface
            || $data instanceof __PHP_Incomplete_Class) {
            return parent::normalize($data, $depth);
        }

        if ($data instanceof UnitEnum) {
            return $data instanceof BackedEnum ? $data->value : $data->name;
        }

        if ($data instanceof JsonSerializable || $data instanceof Arrayable) {
            $value = $this->normalize($data instanceof JsonSerializable ? $data->jsonSerialize() : $data->toArray(), $depth + 1);

            return is_array($value) && $value !== [] && ! array_is_list($value) ? $this->typed($data, $value) : $value;
        }

        if ($data instanceof Stringable) {
            return (string) $data;
        }

        $value = $this->normalize(get_object_vars($data), $depth + 1);

        return is_array($value) ? $this->typed($data, $value) : $value;
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    private function event(LogRecord $record, ?Throwable $exception, array $properties, ?int $messageBytes = null): string
    {
        $event = $this->header($record, $this->template($this->truncate($record->message, $messageBytes)));

        if ($exception !== null) {
            $event['@x'] = $this->exception($exception, $messageBytes);
        }

        return $this->toJson($event + $properties, true);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    private function shrink(LogRecord $record, ?Throwable $exception, array $properties, string $original): string
    {
        $limit = ['EventBodyLimitBytes' => $this->maxEventSize];
        $dropped = [];

        $sizes = array_map(fn (mixed $value): int => strlen($this->toJson($value, true)), $properties);
        arsort($sizes);

        foreach (array_keys($sizes) as $name) {
            unset($properties[$name]);
            $dropped[] = (string) $name;

            $json = $this->event(
                $record,
                $exception,
                array_replace($this->staticProperties, $properties) + $limit + ['DroppedProperties' => $dropped],
            );

            if (strlen($json) <= $this->maxEventSize) {
                return $json;
            }
        }

        $json = $this->event(
            $record,
            $exception,
            $this->staticProperties + $limit + ($dropped === [] ? [] : ['DroppedProperties' => $dropped]),
            self::TRUNCATED_MESSAGE_BYTES,
        );

        if (strlen($json) <= $this->maxEventSize) {
            return $json;
        }

        return $this->toJson(
            $this->header($record, self::PLACEHOLDER)
                + $this->staticProperties
                + $limit
                + ['EventBodySample' => mb_strcut($original, 0, self::SAMPLE_BYTES, 'UTF-8')],
            true,
        );
    }

    /**
     * @return array{'@t': string, '@mt': string, '@l': string}
     */
    private function header(LogRecord $record, string $template): array
    {
        return [
            '@t' => $record->datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            '@mt' => $template,
            '@l' => SeqLevel::fromMonolog($record->level)->value,
        ];
    }

    private function template(string $message): string
    {
        if (strpbrk($message, '{}') === false) {
            return $message;
        }

        return preg_replace_callback(
            self::TOKEN,
            static fn (array $match): string => $match[0] === '{' || $match[0] === '}' ? $match[0].$match[0] : $match[0],
            $message,
        ) ?? $message;
    }

    private function exception(Throwable $exception, ?int $messageBytes): string
    {
        $rendered = [];

        do {
            $code = (string) $exception->getCode();

            $rendered[] = sprintf(
                "%s%s: %s in %s:%d\nStack trace:\n%s",
                Utils::getClass($exception),
                $code === '0' || $code === '' ? '' : "(code: {$code})",
                $this->truncate($exception->getMessage(), $messageBytes),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString(),
            );
        } while (($exception = $exception->getPrevious()) !== null);

        return implode("\n\nCaused by: ", $rendered);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function properties(array $values): array
    {
        $properties = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            $properties[str_starts_with($name, '@') ? '@'.$name : $name] = $this->property($value);
        }

        return $properties;
    }

    private function property(mixed $value): mixed
    {
        try {
            return $this->normalize($value);
        } catch (Throwable $e) {
            return sprintf('[unserializable %s: %s]', get_debug_type($value), $e->getMessage());
        }
    }

    /**
     * @param  array<array-key, mixed[]|scalar|null>  $value
     * @return array<array-key, mixed[]|scalar|null>
     */
    private function typed(object $object, array $value): array
    {
        return ['$type' => Utils::getClass($object)] + $value;
    }

    private function truncate(string $value, ?int $bytes): string
    {
        if ($bytes === null || strlen($value) <= $bytes) {
            return $value;
        }

        return mb_strcut($value, 0, $bytes, 'UTF-8').'…';
    }
}
