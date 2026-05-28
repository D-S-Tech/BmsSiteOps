<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle status of a knowledge-base document.
 *
 *  - pending    document stored + chunked; chunks have no embeddings yet
 *  - embedding  worker has claimed it and is computing embeddings
 *  - ready      all chunks have embeddings; document is searchable
 *  - failed     embedding errored; error is populated
 */
enum DocumentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Embedding = 'embedding';
    case Ready = 'ready';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Embedding => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }

    /** True iff this state is transient and a worker should pick it up. */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
