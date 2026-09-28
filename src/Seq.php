<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Illuminate\Http\Client\Factory;

final class Seq
{
    private readonly Factory $http;

    public function __construct(?Factory $http = null)
    {
        $this->http = $http ?? new Factory;
    }

    public function http(): Factory
    {
        return $this->http;
    }
}
