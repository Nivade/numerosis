<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Billing;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * This checkout already has a live subscription against it.
 *
 * Thrown when the same SetupIntent is resolved twice — a double-click, or a
 * replayed return from the payment provider — which would otherwise charge
 * the customer a second time for the same domain.
 */
class CheckoutAlreadyCompleted extends DomainException {}
