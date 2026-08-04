<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing\Checkout;

use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Services\Billing\Checkout\CheckoutIntentResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Nvade\Numerosis\Tests\TestCase;

class CheckoutIntentResponseTest extends TestCase
{
    public function test_it_maps_a_redirect_checkout_to_that_url(): void
    {
        $response = CheckoutIntentResponse::for(new RedirectCheckout('https://checkout.stripe.com/pay/cs_test_123'));
        $httpResponse = $response->toResponse(request());

        $this->assertInstanceOf(RedirectResponse::class, $httpResponse);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_123', $httpResponse->getTargetUrl());
    }

    public function test_it_refuses_to_map_an_inline_checkout(): void
    {
        $this->expectException(RuntimeException::class);

        CheckoutIntentResponse::for(new InlineCheckout('seti_123_secret', 'pk_test_123'));
    }
}
