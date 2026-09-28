<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

final class SeqClient
{
    public const CONTENT_TYPE = 'application/vnd.serilog.clef';

    public function __construct(
        private readonly Factory $http,
        private readonly string $url,
        private readonly ?string $apiKey = null,
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
    ) {}

    public function send(string $payload): Response
    {
        return $this->http
            ->withHeaders($this->apiKey === null ? [] : ['X-Seq-ApiKey' => $this->apiKey])
            ->withOptions(['timeout' => $this->timeout, 'connect_timeout' => $this->connectTimeout])
            ->withBody($payload, self::CONTENT_TYPE)
            ->post($this->endpoint());
    }

    public function endpoint(): string
    {
        return rtrim($this->url, '/').'/ingest/clef';
    }
}
