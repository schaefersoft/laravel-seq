<?php

declare(strict_types=1);

arch('the package uses strict types')
    ->expect('SchaeferSoft\Seq')
    ->toUseStrictTypes();

arch('the package contains no debugging calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'error_log'])
    ->not->toBeUsed();
