<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Billing;

enum PaymentMethodType: string
{
    case Card = 'card';
    case Link = 'link';
    case Ideal = 'ideal';
    case Bancontact = 'bancontact';
    case SepaDebit = 'sepa_debit';
    case Giropay = 'giropay';
    case Eps = 'eps';
    case Blik = 'blik';

    /**
     * Types a completed checkout may reuse on a later purchase. Redirect
     * methods attach nothing directly. See `ResolveAttachedPaymentMethod`.
     *
     * @return list<self>
     */
    public static function reusable(): array
    {
        return [self::Card];
    }

    public function isReusable(): bool
    {
        return in_array($this, self::reusable(), true);
    }
}
