<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Billing;

/**
 * The Stripe tax id "type" a VAT number for a billing address country should
 * be created as. Shared by SyncBillingAddress (checkout time) and the tenant
 * Billing page's later add/replace action, in one place so the two never
 * drift on which countries are supported.
 */
enum TaxIdType: string
{
    case GbVat = 'gb_vat';
    case EuVat = 'eu_vat';

    /**
     * Not exhaustive by design: an unsupported country submitting a VAT
     * number is a UI mistake worth surfacing.
     *
     * @var array<string, self>
     */
    private const array BY_COUNTRY = [
        'GB' => self::GbVat,
        'AT' => self::EuVat, 'BE' => self::EuVat, 'BG' => self::EuVat, 'HR' => self::EuVat,
        'CY' => self::EuVat, 'CZ' => self::EuVat, 'DK' => self::EuVat, 'EE' => self::EuVat,
        'FI' => self::EuVat, 'FR' => self::EuVat, 'DE' => self::EuVat, 'GR' => self::EuVat,
        'HU' => self::EuVat, 'IE' => self::EuVat, 'IT' => self::EuVat, 'LV' => self::EuVat,
        'LT' => self::EuVat, 'LU' => self::EuVat, 'MT' => self::EuVat, 'NL' => self::EuVat,
        'PL' => self::EuVat, 'PT' => self::EuVat, 'RO' => self::EuVat, 'SK' => self::EuVat,
        'SI' => self::EuVat, 'ES' => self::EuVat, 'SE' => self::EuVat,
    ];

    public static function forCountry(?string $country): ?self
    {
        if ($country === null) {
            return null;
        }

        return self::BY_COUNTRY[$country] ?? null;
    }
}
