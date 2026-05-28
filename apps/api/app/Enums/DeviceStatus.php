<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operational status of a monitored device, normalized across all source kinds.
 */
enum DeviceStatus: string
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
}
