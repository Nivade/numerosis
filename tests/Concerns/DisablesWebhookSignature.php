<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

/**
 * Lets a test post a hand-built Stripe event to the webhook route.
 *
 * `VerifyWebhookSignature` is a route middleware bound at controller
 * construction time, so the secret has to be gone before that happens.
 * Testbench calls this hook from `setUpTraits()`, after the application has
 * booted and before the test body runs.
 */
trait DisablesWebhookSignature
{
    protected function setUpDisablesWebhookSignature(): void
    {
        config(['cashier.webhook.secret' => null]);
    }
}
