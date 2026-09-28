<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Tests\Fixtures;

final class Customer
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        private readonly string $password = 'secret',
    ) {}
}
