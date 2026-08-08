<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Billing;

/**
 * Maps a billing address country to the Stripe tax id "type" a VAT number
 * for that country should be created as. Shared by SyncBillingAddress
 * (checkout time) and the tenant Billing page's later add/replace action —
 * one place so the two never drift on which countries are supported.
 */
final class TaxIdType
{
    private function __construct() {}

    /**
     * Not exhaustive by design: an unsupported country submitting a VAT
     * number is a UI mistake to surface, not silently ignore.
     */
    private const array BY_COUNTRY = [
        'GB' => 'gb_vat',
        'AT' => 'eu_vat', 'BE' => 'eu_vat', 'BG' => 'eu_vat', 'HR' => 'eu_vat',
        'CY' => 'eu_vat', 'CZ' => 'eu_vat', 'DK' => 'eu_vat', 'EE' => 'eu_vat',
        'FI' => 'eu_vat', 'FR' => 'eu_vat', 'DE' => 'eu_vat', 'GR' => 'eu_vat',
        'HU' => 'eu_vat', 'IE' => 'eu_vat', 'IT' => 'eu_vat', 'LV' => 'eu_vat',
        'LT' => 'eu_vat', 'LU' => 'eu_vat', 'MT' => 'eu_vat', 'NL' => 'eu_vat',
        'PL' => 'eu_vat', 'PT' => 'eu_vat', 'RO' => 'eu_vat', 'SK' => 'eu_vat',
        'SI' => 'eu_vat', 'ES' => 'eu_vat', 'SE' => 'eu_vat',
    ];

    public static function forCountry(?string $country): ?string
    {
        if ($country === null) {
            return null;
        }

        return self::BY_COUNTRY[$country] ?? null;
    }
}
