<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SchaeferSoft\Seq\Facades\Seq;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $url = getenv('SEQ_INTEGRATION_URL');

    if (! is_string($url) || $url === '') {
        $this->markTestSkipped('Set SEQ_INTEGRATION_URL to run the integration tests against a Seq server.');
    }

    $this->seqUrl = rtrim($url, '/');
    $this->runId = (string) Str::uuid();

    config()->set('seq.url', $this->seqUrl);
    config()->set('seq.api_key', null);
    Seq::http()->preventStrayRequests(false);
    Log::shareContext(['RunId' => $this->runId]);
});

it('ingests structured events', function () {
    Log::channel('seq')->info('User {user_id} signed in from {ip}', ['user_id' => 42, 'ip' => '10.0.0.1']);
    $this->app->terminate();

    $event = seqEvent($this->seqUrl, $this->runId);

    expect($event['RenderedMessage'])->toBe('User 42 signed in from 10.0.0.1')
        ->and($event['Level'])->toBe('Information')
        ->and(seqProperties($event))->toMatchArray([
            'user_id' => 42,
            'ip' => '10.0.0.1',
            'Application' => 'Testing',
        ]);
});

it('maps every monolog level to a seq level', function () {
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::channel('seq')->log($level, 'Level {level}', ['level' => $level]);
    }

    Seq::flush();

    $levels = collect(seqEvents($this->seqUrl, $this->runId, 8))
        ->mapWithKeys(fn (array $event): array => [seqProperties($event)['level'] => $event['Level']])
        ->sortKeys()
        ->all();

    expect($levels)->toBe([
        'alert' => 'Fatal',
        'critical' => 'Fatal',
        'debug' => 'Debug',
        'emergency' => 'Fatal',
        'error' => 'Error',
        'info' => 'Information',
        'notice' => 'Information',
        'warning' => 'Warning',
    ]);
});

it('renders exceptions', function () {
    Log::channel('seq')->error('Checkout failed', [
        'exception' => new RuntimeException('Card declined', 0, new LogicException('Gateway timeout')),
    ]);

    Seq::flush();

    $event = seqEvent($this->seqUrl, $this->runId);

    expect($event['Level'])->toBe('Error')
        ->and($event['Exception'])
        ->toStartWith('RuntimeException: Card declined in ')
        ->toContain('Caused by: LogicException: Gateway timeout in ');
});

it('renders messages with literal braces as written', function () {
    Log::channel('seq')->info('Blade {{ $user->name }} failed for {"id":1} and user {user_id}', ['user_id' => 5]);

    Seq::flush();

    expect(seqEvent($this->seqUrl, $this->runId)['RenderedMessage'])
        ->toBe('Blade {{ $user->name }} failed for {"id":1} and user 5');
});

it('keeps the batch when a single event exceeds the event size limit', function () {
    Log::channel('seq')->info('Before');
    Log::channel('seq')->info('Huge {kind}', ['kind' => 'payload', 'payload' => str_repeat('x', 400_000)]);
    Log::channel('seq')->info('After');

    Seq::flush();

    $events = collect(seqEvents($this->seqUrl, $this->runId, 3))->keyBy('RenderedMessage');

    expect($events->keys()->sort()->values()->all())->toBe(['After', 'Before', 'Huge payload'])
        ->and(seqProperties($events['Huge payload']))->toMatchArray([
            'DroppedProperties' => ['payload'],
            'EventBodyLimitBytes' => 262144,
        ]);
});

it('splits batches that exceed the request size limit of seq', function () {
    foreach (range(1, 55) as $index) {
        Log::channel('seq')->info('Chunk {index}', ['index' => $index, 'blob' => str_repeat('y', 200_000)]);
    }

    Seq::flush();

    expect(seqCount($this->seqUrl, $this->runId, 55))->toBe(55);
});

it('authenticates with the api key', function () {
    $key = (new Factory)->post($this->seqUrl.'/api/apikeys', [
        'Title' => 'laravel-seq integration test '.$this->runId,
        'InputSettings' => ['AppliedProperties' => [['Name' => 'IngestedWith', 'Value' => 'laravel-seq']]],
        'AssignedPermissions' => ['Ingest'],
    ])->throw()->json();

    config()->set('seq.api_key', $key['Token']);

    Log::channel('seq')->info('Authenticated');
    Seq::flush();

    expect(seqProperties(seqEvent($this->seqUrl, $this->runId)))->toMatchArray(['IngestedWith' => 'laravel-seq']);

    (new Factory)->delete($this->seqUrl.'/api/apikeys/'.$key['Id']);
});

it('ships buffered events when the process dies from a fatal error', function () {
    $script = tempnam(sys_get_temp_dir(), 'seq-fatal-');

    file_put_contents($script, <<<'PHP'
        <?php

        require $argv[1];

        $logger = new Monolog\Logger('fatal', [
            new SchaeferSoft\Seq\SeqHandler(
                new SchaeferSoft\Seq\SeqClient(new Illuminate\Http\Client\Factory, $argv[2]),
            ),
        ]);

        $logger->info('Logged right before a fatal error', ['RunId' => $argv[3]]);

        ini_set('memory_limit', '32M');

        $allocation = str_repeat('x', 64 * 1024 * 1024);
        PHP);

    $process = new Process([PHP_BINARY, $script, dirname(__DIR__, 2).'/vendor/autoload.php', $this->seqUrl, $this->runId]);
    $process->run();
    unlink($script);

    expect($process->getOutput().$process->getErrorOutput())->toContain('Allowed memory size')
        ->and(seqEvent($this->seqUrl, $this->runId)['RenderedMessage'])->toBe('Logged right before a fatal error');
});
