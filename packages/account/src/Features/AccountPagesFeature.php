<?php

declare(strict_types=1);

namespace Nvade\NumerosisAccount\Features;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Support\Ui\AccountPages;

/**
 * The account UI: settings (profile, password, appearance), the workspace
 * list, the billing portal and invoice downloads.
 *
 * Registered by this package's own service provider through
 * `Features::register()`, not by an entry in core's `numerosis.features`
 * array — a satellite's feature class must never be named in core's config,
 * or core boots against a class that may not be installed. Uninstall this
 * package (or `Features::forceForTesting()` around it) for no account UI at
 * all; core's own redirects fall back to `home`.
 *
 * The name is core's constant, {@see AccountPages::FEATURE}, for the same
 * reason: the call sites that ask whether these pages exist live in core.
 *
 * The password settings page also needs `PasswordResetFeature` enabled.
 */
class AccountPagesFeature implements NamedFeature
{
    public const NAME = AccountPages::FEATURE;

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time, and the
        // routes are contributed from the service provider's register phase.
    }
}
