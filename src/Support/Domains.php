<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

/**
 * Derives this package's domain defaults from `APP_URL`, so a host that
 * configures nothing still boots with correct values.
 *
 * Deliberately reads the environment rather than `config('app.url')`: these
 * methods are called from `config/numerosis.php` while the config repository
 * is still being built, and `config()` at that point is either unavailable or
 * answers with a half-populated repository. That is also why nothing here
 * throws — a config file that throws takes the whole application down before
 * any error handler exists, and the failure names the config file rather than
 * the missing env key.
 *
 * Reads the superglobals directly rather than calling `env()`, for two
 * reasons that happen to agree. Larastan's `noEnvCallsOutsideOfConfig` rule
 * forbids `env()` in `src/` because a cached config makes it return null —
 * which does not apply here, since the only caller *is* a config file and the
 * derived value is baked in at cache time — but the rule is right that this
 * class must never be called at runtime, and honouring it keeps that true by
 * construction. `env()` would also be one more thing resolving through the
 * container this early.
 *
 * The values are *defaults*. A host that wants something else sets
 * `NUMEROSIS_APEX_DOMAIN` / `NUMEROSIS_CENTRAL_DOMAIN` and never reaches
 * these at all.
 */
final class Domains
{
    /**
     * Hosts that have no registrable apex to strip a label from. `localhost`
     * and bare IPs are the two that come up in practice: taking the "domain"
     * of `127.0.0.1` by dropping its first label yields `0.0.1`, which is
     * worse than useless because it looks like a domain.
     */
    private const NOT_SUBDIVIDABLE = ['localhost'];

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
     * All three sources, because Laravel's dotenv adapter writes to `$_ENV`
     * and `$_SERVER` and only optionally to the process environment — a
     * host that has disabled `putenv` would otherwise read as unset here.
     */
    private static function appUrl(): string
    {
        $value = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The registrable domain tenant subdomains hang off.
     *
     * A three-or-more-label host is assumed to carry a central subdomain
     * (`app.example.com` ⇒ `example.com`), which is the shape this package's
     * own deployments use; two labels are already the apex
     * (`example.com` ⇒ `example.com`). The heuristic is wrong for multi-part
     * public suffixes — `app.example.co.uk` yields `example.co.uk` correctly
     * by luck of label count, but `example.co.uk` alone would be reduced to
     * `co.uk`. A host on such a domain must set `NUMEROSIS_APEX_DOMAIN`
     * explicitly; resolving this properly needs the Public Suffix List, which
     * is not a dependency worth adding for a default.
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
