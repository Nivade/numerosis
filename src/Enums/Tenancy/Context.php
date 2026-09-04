<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

use Illuminate\Support\Facades\Config;

enum Context: string
{
    case Central = 'central';
    case Tenant = 'tenant';

    /**
     * The current context, decided by whether tenancy is initialized.
     *
     * Ask here, never by inspecting the default guard: anything calling
     * `Auth::shouldUse()` moves that for the rest of the request.
     */
    public static function current(): self
    {
        return tenancy()->initialized ? self::Tenant : self::Central;
    }

    /**
     * The guard that authenticates users in this context. Read guard names
     * through here, never by naming `numerosis.auth.guards.*` yourself.
     */
    public function guard(): string
    {
        return Config::string("numerosis.auth.guards.{$this->value}");
    }

    /**
     * The inverse of {@see guard()}. Anything but the central guard counts
     * as tenant.
     */
    public static function fromGuard(string $guardName): self
    {
        return $guardName === self::Central->guard() ? self::Central : self::Tenant;
    }
}
