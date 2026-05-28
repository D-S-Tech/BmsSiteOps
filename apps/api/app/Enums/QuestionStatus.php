<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle status of a Site Q&A question.
 *
 *  - pending  row created; the orchestration pipeline has not finished
 *  - ready    the answer is populated; citations are in metadata
 *  - failed   pipeline errored; `error` is populated
 */
enum QuestionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
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
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
