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

    public function icon(): string
    {
        return match ($this) {
            self::Idle => 'heroicon-o-clock',
            self::Busy => 'heroicon-o-no-symbol',
            self::Invisible => 'heroicon-o-eye-slash',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Idle => 'text-yellow-400',
            self::Busy => 'text-red-500',
            self::Invisible => 'text-zinc-400',
        };
    }

    public function dotColor(): string
    {
        return match ($this) {
            self::Idle => 'bg-yellow-400',
            self::Busy => 'bg-red-500',
            self::Invisible => 'bg-zinc-400',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Idle => 'Away from keyboard',
            self::Busy => 'Do not disturb',
            self::Invisible => 'Appear offline to others',
        };
    }
}
