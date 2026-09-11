<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

/**
 * Derives this package's domain defaults from `APP_URL`, for a host that sets
 * neither `NUMEROSIS_APEX_DOMAIN` nor `NUMEROSIS_CENTRAL_DOMAIN`.
 *
 * Called from `config/numerosis.php` while the config repository is still
 * being built, so nothing here may use a facade, `config()`, `env()`, or
 * throw: an exception at that point has no handler to reach.
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
     * The hostname the app itself answers on: `APP_URL`'s host, verbatim.
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
     * The domain tenant subdomains hang off. Three or more labels are read as
     * an apex plus a central subdomain (`app.example.com` gives
     * `example.com`); two labels are already the apex. Counting labels cannot
     * recognise a multi-part suffix, so `example.co.uk` wrongly reduces to
     * `co.uk`; set `NUMEROSIS_APEX_DOMAIN` explicitly there.
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
