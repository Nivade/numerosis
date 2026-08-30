<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Social;

use Illuminate\Support\Facades\Config;

/**
 * The social providers that are both described (numerosis.social.providers)
 * and actually usable (a client id present in
 * config/services.php). This is the same check
 * Nvade\NumerosisAuthUi\Http\Controllers\Socialite\Login::ensureProviderIsConfigured() applies
 * on the callback side — a provider with no credentials would render a button
 * that always fails.
 *
 * The test is `filled(config("services.{$key}.client_id"))`, NOT membership in
 * array_keys(config('services')), which is what the two Blade copies did.
 * That was wrong twice over:
 *
 *   1. config/services.php declares `'client_id' => env(key: 'GOOGLE_CLIENT_ID',
 *      default: '')` for both google and discord, so the `google` and `discord`
 *      keys exist unconditionally. A deployment with no OAuth credentials at
 *      all still rendered both buttons, and the failure only surfaced at the
 *      provider's own callback.
 *   2. config('services') also holds postmark, ses, resend, slack and
 *      turnstile. The intersection is only harmless today because none of
 *      those names also appears in numerosis.social.providers; adding a mail
 *      service whose key collides with a provider name would render a button
 *      for it.
 */
final class ConfiguredProviders
{
    /**
     * The feature name social login is gated on, owned here rather than on
     * the feature class itself.
     *
     * `SocialLoginFeature` lives in `nvade/numerosis-auth-ui`, and core has
     * three views that must ask "is social login on?" — the invitation-accept
     * screen, the auth button grid, and the tenant panel's connected-accounts
     * manager. Reading `SocialLoginFeature::NAME` there would autoload a
     * class that may not be installed (unlike a bare `use` import, a constant
     * fetch *does* trigger autoloading), turning an absent optional package
     * into a fatal on three core screens. The satellite's own constant is
     * defined as `= ConfiguredProviders::FEATURE`, so the two cannot drift.
     */
    public const FEATURE = 'social';

    /**
     * @return array<string, array{label: string, hover: string, icon: string}>
     */
    public static function all(): array
    {
        /** @var array<string, array{label: string, hover: string, icon: string}> $all */
        $all = Config::array('numerosis.social.providers');

        $providers = [];

        foreach ($all as $key => $meta) {
            if (filled(Config::get("services.{$key}.client_id"))) {
                $providers[$key] = $meta;
            }
        }

        return $providers;
    }
}
