<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Nvade\Numerosis\Contracts\Exceptions\ProvidesExceptionContext;
use WeakMap;

/**
 * Exception context and throttling for `withExceptions()`. A job that failed
 * inside `Concerns\Tenancy\RunsInTenant::runInTenant()` needs
 * `Concerns\Tenancy\TagsSentryScopeWithTenant` to be tagged; this covers
 * everything else.
 */
final class ExceptionRegistrar
{
    /**
     * Replaces this class's registration entirely. Receives the `Exceptions`
     * instance instead of the `Application`, because it runs before the
     * container exists.
     */
    public static ?Closure $registerCallback = null;

    /**
     * Tracks which `Handler` instances {@see self::apply()} has already
     * registered against.
     *
     * @var WeakMap<Handler, true>|null
     */
    private static ?WeakMap $registeredFor = null;

    public static function apply(Exceptions $exceptions): void
    {
        if (self::$registerCallback instanceof Closure) {
            (self::$registerCallback)($exceptions);

            return;
        }

        self::$registeredFor ??= new WeakMap;

        if (isset(self::$registeredFor[$exceptions->handler])) {
            return;
        }

        self::$registeredFor[$exceptions->handler] = true;

        // Resolved at report time instead of here, since this runs before
        // the container exists.
        $exceptions->context(fn (): array => resolve(ProvidesExceptionContext::class)->handle());

        $exceptions->dontReportDuplicates();

        $exceptions->throttle(fn () => Limit::perMinute(30));
    }
}
