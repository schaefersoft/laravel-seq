<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void flush()
 * @method static \Illuminate\Http\Client\Factory fake(callable|array<string, mixed>|null $responses = null)
 * @method static \Illuminate\Http\Client\Factory http()
 *
 * @see \SchaeferSoft\Seq\Seq
 */
class Seq extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SchaeferSoft\Seq\Seq::class;
    }
}
