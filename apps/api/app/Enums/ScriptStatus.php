<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle status of a script generation request.
 *
 *  - requested   row created; waiting for a worker to claim it
 *  - generating  a worker has claimed it and is calling the LLM (transient)
 *  - ready       generation succeeded; `content` is populated
 *  - failed      generation errored; `error` is populated
 */
enum ScriptStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'gray',
            self::Generating => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }

    /** True iff this state is transient and a poller should keep polling. */
    public function isPending(): bool
    {
        return $this === self::Requested || $this === self::Generating;
    }
}
