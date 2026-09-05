<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenant;

/**
 * The presence status a user sets for themselves, alongside the online state
 * derived from their activity.
 */
enum DisplayStatus: string
{
    case Idle = 'idle';
    case Busy = 'busy';
    case Invisible = 'invisible';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Idle',
            self::Busy => 'Busy',
            self::Invisible => 'Invisible',
        };
    }
}
