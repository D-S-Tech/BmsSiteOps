<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Transport used to reach a Niagara station.
 *
 * A Niagara station can be reached several ways; the collector picks the
 * implementation from this value. Only meaningful when SourceKind is Niagara.
 *
 * Mirrors `NiagaraTransport` handling in the Python worker
 * (apps/worker/app/collectors/niagara.py).
 */
enum NiagaraTransport: string implements HasLabel
{
    case Obix = 'obix';   // oBIX (OASIS standard) over HTTP/XML — primary
    case Rest = 'rest';   // Niagara 4 built-in REST API (JSON)
    case Fox = 'fox';     // native Tridium Fox protocol — experimental

    public function label(): string
    {
        return match ($this) {
            self::Obix => 'oBIX (HTTP/XML)',
            self::Rest => 'Niagara REST (JSON)',
            self::Fox => 'Fox (experimental)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
