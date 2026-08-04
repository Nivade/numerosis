<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

/**
 * Determines where to send a user immediately after a successful login. Bind
 * a replacement in a service provider to send users somewhere other than the
 * default (an intended URL for the current host, or the tenant/central
 * landing page).
 */
interface ResolvesPostLoginRedirectUrl
{
    public function url(): string;
}
