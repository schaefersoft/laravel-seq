<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Monolog\Level;
use Monolog\LogRecord;
use SchaeferSoft\Seq\Seq;
use SchaeferSoft\Seq\SeqConfig;
use Throwable;

final class TestConnectionCommand extends Command
{
    protected $signature = 'seq:test {--channel=seq : The log channel whose Seq settings are tested}';

    protected $description = 'Send a test event to Seq and report whether it was accepted';

    public function handle(Repository $config, Seq $seq): int
    {
        $channel = $this->option('channel');
        $channel = is_string($channel) ? $channel : 'seq';
        $channelConfig = $config->get("logging.channels.{$channel}");

        if (! is_array($channelConfig) || ($channelConfig['driver'] ?? null) !== 'seq') {
            $this->components->error("The [{$channel}] log channel does not use the seq driver.");

            return self::FAILURE;
        }

        $defaults = $config->get('seq', []);

        try {
            $settings = SeqConfig::fromArray(array_replace(is_array($defaults) ? $defaults : [], $channelConfig));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($settings->url === null) {
            $this->components->error('No Seq URL is configured. Set SEQ_URL in your environment.');

            return self::FAILURE;
        }

        if (! $settings->enabled) {
            $this->components->warn('Seq logging is disabled (SEQ_ENABLED=false). Sending the test event anyway.');
        }

        $client = $settings->client($seq->http());

        $event = $settings->formatter()->format(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: $channel,
            level: Level::Info,
            message: 'Seq connection test via the {Channel} log channel',
            context: ['Channel' => $channel],
        ));

        try {
            $response = $client->send($event."\n");
        } catch (Throwable $e) {
            $this->components->error("Could not reach Seq at {$client->endpoint()}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->components->info("Seq accepted the test event at {$client->endpoint()} (HTTP {$response->status()}).");

            return self::SUCCESS;
        }

        $error = $response->json('Error');

        $this->components->error(sprintf(
            'Seq rejected the test event at %s with HTTP %d: %s',
            $client->endpoint(),
            $response->status(),
            is_string($error) ? $error : $response->body(),
        ));

        match ($response->status()) {
            401, 403 => $this->components->warn('Check SEQ_API_KEY: the key is missing, invalid or lacks the Ingest permission.'),
            404 => $this->components->warn('Check SEQ_URL: it must point to the root of your Seq server, e.g. https://seq.example.com.'),
            default => null,
        };

        return self::FAILURE;
    }
}
