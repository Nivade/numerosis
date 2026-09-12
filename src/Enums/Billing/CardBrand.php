<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Billing;

/**
 * Stripe's own card brand slugs. Classes are spelled out literally, never
 * interpolated — see `Nvade\NumerosisUi\Enums\Severity` for why: Tailwind's
 * `@source` scanner needs a complete class string as raw text, and this file
 * is named in `resources/theme-src/app.css` / `resources/css/app.css` for
 * that reason.
 */
enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Amex = 'amex';
    case Discover = 'discover';
    case Diners = 'diners';
    case Jcb = 'jcb';
    case UnionPay = 'unionpay';

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'VISA',
            self::Mastercard => 'MC',
            self::Amex => 'AMEX',
            self::Discover => 'DISC',
            self::Diners => 'DINERS',
            self::Jcb => 'JCB',
            self::UnionPay => 'UP',
        };
    }

    public function chipClasses(): string
    {
        return match ($this) {
            self::Visa => 'bg-blue-600 text-white',
            self::Mastercard => 'bg-orange-500 text-white',
            self::Amex => 'bg-sky-700 text-white',
            self::Discover => 'bg-orange-400 text-white',
            self::Diners => 'bg-zinc-700 text-white',
            self::Jcb => 'bg-emerald-600 text-white',
            self::UnionPay => 'bg-red-600 text-white',
        };
    }
}
