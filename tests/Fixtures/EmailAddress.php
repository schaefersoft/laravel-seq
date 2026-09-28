<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Tests\Fixtures;

use Stringable;

final class EmailAddress implements Stringable
{
    public function __construct(private readonly string $address) {}

    public function __toString(): string
    {
        return $this->address;
    }
}
