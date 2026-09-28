<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Tests\Fixtures;

use JsonSerializable;
use RuntimeException;

final class ExplodingValue implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('Cannot serialize');
    }
}
