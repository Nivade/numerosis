<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Billing;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * The SetupIntent came back from Stripe (or from a redirect-method bank
 * page) without a usable payment method attached.
 */
class SetupIntentNotConfirmed extends DomainException {}
