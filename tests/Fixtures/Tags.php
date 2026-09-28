<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Tests\Fixtures;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<int, string>
 */
final class Tags implements Arrayable
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(private readonly array $tags) {}

    public function toArray(): array
    {
        return $this->tags;
    }
}
