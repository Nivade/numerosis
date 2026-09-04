<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;

/**
 * How a request is matched to a tenant. See config('numerosis.tenancy.identification.mode')'s
 * docblock in config/numerosis.php for what each mode means; see
 * .ai/rules/identification-modes.md for the mechanics each one relies
 * on and the one thing (Path mode's route-parameter conflict with the
 * own tenant resolution) that isn't provable by this repo's test harness.
 */
enum IdentificationMode: string
{
    case Subdomain = 'subdomain';
    case CustomDomain = 'custom_domain';
    case Path = 'path';

    /**
     * `Numerosis::middleware()` calls `TenancyServiceProvider::identificationMiddleware()`,
     * which calls this, from `bootstrap/app.php`'s `withMiddleware(...)` —
     * and `ApplicationBuilder::withMiddleware()` registers that callback via
     * `afterResolving(HttpKernel::class, ...)` *and*
     * `afterResolving(ConsoleKernel::class, ...)`. Both fire the instant
     * either kernel is first resolved from the container, which happens
     * before that kernel's own `bootstrap()` call — i.e. before
     * `RegisterFacades` has run, on every real request and every `artisan`
     * invocation (not under Testbench, which boots the whole app first).
     * `Config::string()` would otherwise throw `A facade root has not been
     * set`, fatally, before the exception handler even exists to catch it —
     * same class of bug as `Domains::appUrl()`'s
     * (`.ai/rules/package-host-bootstrap.md`), reached through a
     * different door. Falling back to the default here is safe because
     * `NumerosisServiceProvider::registerMiddleware()` unconditionally
     * re-registers the real value later, from `packageBooted()`, once
     * config is actually loaded — this only has to survive long enough not
     * to crash the process before that runs.
     */
    public static function current(): self
    {
        if (Facade::getFacadeApplication() === null) {
            return self::Subdomain;
        }

        return self::from(Config::string(
            'numerosis.tenancy.identification.mode',
            self::Subdomain->value,
        ));
    }

    /**
     * Whether this mode identifies tenants through a row in the `domains`
     * table at all. Path mode resolves purely by tenant id, so no `Domain`
     * row is ever created or read for it.
     */
    public function usesDomainRecord(): bool
    {
        return $this !== self::Path;
    }
}
