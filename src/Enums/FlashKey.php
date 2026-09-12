<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums;

/**
 * Not domain-scoped — it names a session-flash key, and flashes cross auth,
 * tenancy and billing indiscriminately. One case, deliberately: `status` was
 * already the convention at most call sites, so this converges Billing's
 * `success`/`info`/`error` keys onto it rather than the reverse.
 */
enum FlashKey: string
{
    case Status = 'status';
}
