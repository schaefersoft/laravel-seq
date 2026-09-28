<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;
use Throwable;

final class SeqHandler extends AbstractProcessingHandler
{
    public const MAX_PAYLOAD_BYTES = 1048576;

    private readonly int $batchSize;

    /** @var list<string> */
    private array $buffer = [];

    private ?float $bufferedSince = null;

    private bool $flushing = false;

    private bool $flushesOnShutdown = false;

    public function __construct(
        private readonly SeqClient $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        int $batchSize = 100,
        private readonly float $flushInterval = 5.0,
    ) {
        parent::__construct($level, $bubble);

        $this->batchSize = max(1, $batchSize);
    }

    public function handle(LogRecord $record): bool
    {
        try {
            return parent::handle($record);
        } catch (Throwable) {
            return false;
        }
    }

    public function flush(): void
    {
        if ($this->flushing || $this->buffer === []) {
            return;
        }

        $this->flushing = true;
        $events = $this->buffer;
        $this->buffer = [];
        $this->bufferedSince = null;

        try {
            foreach ($this->payloads($events) as $payload) {
                $this->client->send($payload);
            }
        } catch (Throwable) {
        } finally {
            $this->flushing = false;
        }
    }

    public function close(): void
    {
        $this->flush();
    }

    public function reset(): void
    {
        $this->flush();

        parent::reset();
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = is_string($record->formatted)
            ? $record->formatted
            : Utils::jsonEncode($record->formatted, ignoreErrors: true);

        $loggedAt = (float) $record->datetime->format('U.u');
        $this->bufferedSince ??= $loggedAt;

        if (! $this->flushesOnShutdown) {
            register_shutdown_function($this->flush(...));
            $this->flushesOnShutdown = true;
        }

        if (count($this->buffer) >= $this->batchSize || $this->isOverdue($loggedAt)) {
            $this->flush();
        }
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new ClefFormatter;
    }

    private function isOverdue(float $loggedAt): bool
    {
        return $this->flushInterval > 0 && $loggedAt - $this->bufferedSince >= $this->flushInterval;
    }

    /**
     * @param  list<string>  $events
     * @return iterable<int, string>
     */
    private function payloads(array $events): iterable
    {
        $payload = '';
        $count = 0;

        foreach ($events as $event) {
            if ($count > 0 && ($count >= $this->batchSize || strlen($payload) + strlen($event) + 1 > self::MAX_PAYLOAD_BYTES)) {
                yield $payload;

                $payload = '';
                $count = 0;
            }

            $payload .= $event."\n";
            $count++;
        }

        if ($count > 0) {
            yield $payload;
        }
    }
}
