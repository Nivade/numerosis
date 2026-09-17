<?php

declare(strict_types=1);

return [

    'checkout' => [
        'subscribe' => 'Subscribe',
        'processing' => 'Processing…',
        'session_expired' => 'This checkout session has expired. Please start again.',
        'foreign_session' => 'This checkout session belongs to a different account.',
        'confirmation_failed' => 'Payment could not be confirmed. Please try again.',
        'already_subscribed' => 'This checkout has already been completed.',
        'requires_verification' => 'Your bank requires additional verification. Please try again.',
        'setting_up' => 'Your tenant is being set up.',
        'confirming_payment' => 'Confirming your payment. This can take a moment.',
        'secure_notice' => 'Payments are processed securely by Stripe. We never see your card details.',
        'invalid_vat_number' => 'That VAT number could not be validated. Please check it and try again.',
        'vat_country_unsupported' => 'A VAT number can only be added for an EU or UK billing address.',
        'saved_billing_fetch_failed' => 'Couldn\'t load your saved billing info — enter it fresh below.',
        'saved_payment_methods_fetch_failed' => 'Couldn\'t load your saved payment methods — enter a new one below.',
        'use_different_payment_method' => 'Use a different payment method',
        'saved_payment_method_unavailable' => 'This payment method is no longer available. Please use a different card.',
        'saved_payment_methods_heading' => 'Payment method',
        'default_payment_method' => 'Default',
        'expires' => 'Expires :month/:year',
        'unbranded_card' => 'Card',
    ],

    'promotion' => [
        'label' => 'Promotion code',
        'placeholder' => 'Have a code?',
        'apply' => 'Apply',
        'remove' => 'Remove',
        'applied' => ':label applied',
        'unknown' => "We can't find the code :code. Check it and try again.",
        'expired' => 'That code has expired.',
        'exhausted' => 'That code has reached its redemption limit.',
        'other_customer' => 'That code belongs to a different account.',
        'first_purchase_only' => 'That code is for a first subscription only.',
        'product_restricted' => "That code doesn't apply to the plan you picked.",
        'minimum_amount' => 'That code needs an order of at least :amount.',
        'unreadable' => 'We could not check that code just now. Please try again.',
        'retention_offer' => 'Stay with :discount',
    ],

    'awaiting_payment' => [
        'title' => 'Payment settling',
        'description' => "Your payment method needs a few days to clear. We'll email you once it's confirmed — everything works in the meantime.",
    ],

    /*
    |--------------------------------------------------------------------------
    | Decline codes
    |--------------------------------------------------------------------------
    |
    | Stripe's own error.message is written for developers, not customers
    | ("Your card was declined."  with no next step). Mapped client-side in
    | stripe-checkout.js — passed the whole array via @js() rather than
    | duplicated as a JS literal, so this file stays the one place the copy
    | is edited. Keys are Stripe's PaymentIntent/SetupIntent
    | last_payment_error.decline_code and .code values; unmapped codes fall
    | back to Stripe's own message.
    |
    */

    'decline_codes' => [
        'insufficient_funds' => 'Your card was declined for insufficient funds. Try another card, or contact your bank.',
        'card_declined' => 'Your bank declined this card. Try another card, or contact your bank to authorise the payment.',
        'expired_card' => 'This card has expired. Please try a different card.',
        'incorrect_cvc' => 'The security code you entered is incorrect. Please check the back of your card and try again.',
        'processing_error' => 'Something went wrong processing your card. Please try again in a moment.',
        'incorrect_number' => 'The card number you entered is incorrect.',
        'authentication_required' => 'Your bank requires additional authentication for this card. Please try again.',
    ],

];
