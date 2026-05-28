<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Health/connectivity status of a source's last poll attempt.
 */
enum SourceStatus: string implements HasColor, HasLabel
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

    public function color(): string
    {
        return match ($this) {
            self::Never => 'gray',
            self::Ok => 'success',
            self::Error => 'danger',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }
}
