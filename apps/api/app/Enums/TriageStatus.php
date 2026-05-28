<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle status of a triage decision.
 *
 *  - pending  — rule matched, action not yet executed
 *  - executed — action ran successfully
 *  - failed   — action attempted and threw
 *  - skipped  — matched, but the action is a no-op for this case
 */
enum TriageStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Executed = 'executed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Executed => 'success',
            self::Failed => 'danger',
            self::Skipped => 'gray',
        };
    }
}
