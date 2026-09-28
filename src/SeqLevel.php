<?php

declare(strict_types=1);

namespace SchaeferSoft\Seq;

use Monolog\Level;

enum SeqLevel: string
{
    case Debug = 'Debug';
    case Information = 'Information';
    case Warning = 'Warning';
    case Error = 'Error';
    case Fatal = 'Fatal';

    public static function fromMonolog(Level $level): self
    {
        return match ($level) {
            Level::Debug => self::Debug,
            Level::Info, Level::Notice => self::Information,
            Level::Warning => self::Warning,
            Level::Error => self::Error,
            Level::Critical, Level::Alert, Level::Emergency => self::Fatal,
        };
    }
}
