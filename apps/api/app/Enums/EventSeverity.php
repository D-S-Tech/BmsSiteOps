<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Severity of a normalized event, used for triage ordering and alerting.
 */
enum EventSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }

    /** Higher is more severe — useful for sorting/threshold comparisons. */
    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }
}
