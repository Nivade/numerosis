<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Billing;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * The pending checkout row already carries a live (non-cancelled) Stripe
 * subscription id. Reached when the same SetupIntent is resolved a second
 * time — a double-click, a replayed subscribe(), or a re-hit of
 * checkout.subscription.return — which would otherwise run
 * CreateInlineSubscription again and charge the customer twice for the same
 * domain. See .claude/rules/billing-checkout.md.
 */
class CheckoutAlreadyCompleted extends DomainException {}
