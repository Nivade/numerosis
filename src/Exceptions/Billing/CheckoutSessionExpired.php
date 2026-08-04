<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Billing;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * The pending checkout row is missing, or belongs to someone else. Fail
 * closed: this covers both "reservation genuinely expired" and "someone is
 * trying a SetupIntent id that isn't theirs".
 */
class CheckoutSessionExpired extends DomainException {}
