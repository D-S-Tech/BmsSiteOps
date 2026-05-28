<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Health/connectivity status of a source's last poll attempt.
 */
enum SourceStatus: string
{
    case Never = 'never';   // never polled yet
    case Ok = 'ok';         // last poll succeeded
    case Error = 'error';   // last poll failed

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never polled',
            self::Ok => 'OK',
            self::Error => 'Error',
        };
    }
}
