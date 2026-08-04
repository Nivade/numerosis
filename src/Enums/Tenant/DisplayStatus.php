<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenant;

/**
 * A user's presence status. Moved into core 2026-08-04 (Phase 10,
 * .claude/plans/opt-in-feature-classes.md) from Nvade\Chat\Enums\DisplayStatus
 * — presence is already core (last_seen_at, UpdateUserLastSeenMiddleware,
 * the 'online' broadcast channel), so the vocabulary describing it belongs
 * here regardless of whether the chat module is installed.
 * Nvade\Chat\Enums\DisplayStatus is now a class_alias for this enum (see
 * Nvade\Chat\Providers\ChatServiceProvider::register()) so the module's own
 * code and any external reference to the old name keep working unchanged.
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
