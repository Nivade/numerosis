<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

/**
 * Derives this package's domain defaults from `APP_URL`, so a host that
 * configures nothing still boots with correct values.
 *
 * These are only defaults. Set `NUMEROSIS_APEX_DOMAIN` or
 * `NUMEROSIS_CENTRAL_DOMAIN` and none of this is consulted.
 *
 * Called from `config/numerosis.php` while the config repository is still
 * being built, which constrains everything here: no facades, no `config()`,
 * no `env()`, and nothing may throw — an exception at that point takes the
 * application down before any handler exists, reported against the config
 * file rather than the missing value. The environment is read through the
 * superglobals for the same reason.
 */
final class Domains
{
    /**
     * Hosts with no apex to strip a label from. Dropping the first label of
     * `127.0.0.1` would yield `0.0.1`, which is worse than nothing because
     * it still looks like a domain.
     */
    private const array NOT_SUBDIVIDABLE = ['localhost'];

    private function __construct() {}

    /**
     * The hostname the app itself answers on — `APP_URL`'s host, verbatim.
     */
    public static function hostFromAppUrl(): string
    {
        $url = self::appUrl();

        if ($url === '') {
            return 'localhost';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * All three sources: the dotenv adapter writes to `$_ENV` and `$_SERVER`
     * but only optionally to the process environment, so a host with
     * `putenv` disabled would otherwise read as unset.
     */
    private static function appUrl(): string
    {
        $value = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The domain tenant subdomains hang off.
     *
     * Three or more labels are read as an apex plus a central subdomain
     * (`app.example.com` gives `example.com`); two labels are already the
     * apex. Counting labels cannot recognise a multi-part suffix, so
     * `example.co.uk` would wrongly reduce to `co.uk` — set
     * `NUMEROSIS_APEX_DOMAIN` explicitly on such a domain.
     */
    public static function apexFromAppUrl(): string
    {
        $host = self::hostFromAppUrl();

        if (in_array($host, self::NOT_SUBDIVIDABLE, true) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $labels = explode('.', $host);

        if (count($labels) <= 2) {
            return $host;
        }

        return implode('.', array_slice($labels, -2));
    }
}
