<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Boot;

use Closure;

/**
 * Shared by the registrars a host can either let run or replace wholesale.
 * The flag is process-lifetime state, which is why the reset below exists.
 */
trait RegistersOnce
{
    /** Replaces this class's registration entirely; receives the `Application`. */
    public static ?Closure $registerCallback = null;

    private static bool $registered = false;

    public static function registered(): bool
    {
        return self::$registered;
    }

    /**
     * For tests only: one test registering would otherwise change what every
     * test after it in the same worker does.
     */
    public static function resetForTesting(): void
    {
        self::$registered = false;
    }
}
