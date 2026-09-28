<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Tests\Fixtures;

use JsonSerializable;

final class Money implements JsonSerializable
{
    public function __construct(private readonly int $amount, private readonly string $currency) {}

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
