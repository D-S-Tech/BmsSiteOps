<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of an external data source.
 *
 * Mirrors `CollectorKind` in the Python worker (apps/worker/app/collectors/base.py).
 * When adding a kind here, add it there too, and implement the collector.
 */
enum SourceKind: string
{
    case Trmm = 'trmm';
    case Niagara = 'niagara';
    case Bacnet = 'bacnet';

    public function label(): string
    {
        return match ($this) {
            self::Trmm => 'Tactical RMM',
            self::Niagara => 'Tridium Niagara',
            self::Bacnet => 'BACnet/IP',
        };
    }
}
