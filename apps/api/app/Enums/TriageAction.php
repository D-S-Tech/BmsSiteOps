<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a triage rule prescribes when it matches an event.
 *
 *  - mute_device     — silence the originating device (indefinitely unless
 *                      the rule's params override). The clearest tool for
 *                      "this device is noisy / under maintenance".
 *  - mark_for_review — surface the matched event for operator attention in
 *                      the triage audit; do not modify other state.
 *  - ignore          — record the match but take no further action. Used to
 *                      silence specific event patterns without muting the
 *                      whole device.
 */
enum TriageAction: string implements HasColor, HasLabel
{
    case MuteDevice = 'mute_device';
    case MarkForReview = 'mark_for_review';
    case Ignore = 'ignore';

    public function getLabel(): string
    {
        return match ($this) {
            self::MuteDevice => 'Mute device',
            self::MarkForReview => 'Mark for review',
            self::Ignore => 'Ignore',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MuteDevice => 'warning',
            self::MarkForReview => 'info',
            self::Ignore => 'gray',
        };
    }
}
