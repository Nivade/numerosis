<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

/**
 * What CheckoutGateway::begin() hands back: something to redirect the
 * browser to, something to mount a Stripe Element against, or "nothing to
 * pay, the tenant already exists". Not directly instantiable;
 * `Nvade\Numerosis\Data\Billing\Intents` holds the three shapes a gateway
 * can return.
 */
abstract class CheckoutIntent extends Data {}
