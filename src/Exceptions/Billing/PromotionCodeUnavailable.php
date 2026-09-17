<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Billing;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * One reason per failure mode. "Invalid code" for all five is the
 * support-ticket generator: a customer who typed a code that is real but
 * exhausted needs to be told that instead of sent back to check their spelling.
 */
class PromotionCodeUnavailable extends DomainException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function unknown(string $code): self
    {
        return new self(__('numerosis::billing.promotion.unknown', ['code' => $code]), 'unknown');
    }

    public static function expired(): self
    {
        return new self(__('numerosis::billing.promotion.expired'), 'expired');
    }

    public static function exhausted(): self
    {
        return new self(__('numerosis::billing.promotion.exhausted'), 'exhausted');
    }

    public static function otherCustomer(): self
    {
        return new self(__('numerosis::billing.promotion.other_customer'), 'other_customer');
    }

    public static function firstPurchaseOnly(): self
    {
        return new self(__('numerosis::billing.promotion.first_purchase_only'), 'first_purchase_only');
    }

    public static function productRestricted(): self
    {
        return new self(__('numerosis::billing.promotion.product_restricted'), 'product_restricted');
    }

    public static function minimumAmount(string $formattedMinimum): self
    {
        return new self(
            __('numerosis::billing.promotion.minimum_amount', ['amount' => $formattedMinimum]),
            'minimum_amount',
        );
    }

    public static function unreadable(): self
    {
        return new self(__('numerosis::billing.promotion.unreadable'), 'unreadable');
    }
}
