<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Operational status of a monitored device, normalized across all source kinds.
 */
enum DeviceStatus: string implements HasColor, HasLabel
{
    case Online = 'online';
    case Offline = 'offline';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Offline => 'danger',
            self::Unknown => 'gray',
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
