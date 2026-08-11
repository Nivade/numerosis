<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

use Illuminate\Support\Facades\Config;

enum Context: string
{
    case Central = 'central';
    case Tenant = 'tenant';

    /**
     * Whether tenancy is currently initialized decides the context.
     *
     * The ambient default guard is *not* a reliable answer to the same
     * question — anything that has called `Auth::shouldUse()` since tenancy
     * bootstrapped moves it, with nothing to move it back. See
     * .claude/rules/auth-guards.md.
     */
    public static function current(): self
    {
        return tenancy()->initialized ? self::Tenant : self::Central;
    }

    /**
     * The name of the guard that authenticates users in this context.
     *
     * Read through here rather than interpolating `numerosis.auth.guards.*`
     * at each call site: a dozen sites wrote that key by hand, and the two
     * that did not — hardcoding `'tenant'` and `'web'` instead — are exactly
     * where it had already drifted.
     */
    public function guard(): string
    {
        return Config::string("numerosis.auth.guards.{$this->value}");
    }

    /**
     * The context a given guard name authenticates in — the inverse of
     * {@see guard()}. Anything but the central guard is treated as tenant,
     * matching {@see current()}'s two-value split.
     */
    public static function fromGuard(string $guardName): self
    {
        return $guardName === self::Central->guard() ? self::Central : self::Tenant;
    }
}
