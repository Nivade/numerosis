<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Features;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Support\Social\ConfiguredProviders;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * The whole OAuth surface: routes, provider buttons, and the connected-
 * accounts manager.
 *
 * Registered by this package's own service provider through
 * `Features::register()`, not by an entry in core's `numerosis.features`
 * array — a satellite's feature class must never be named in core's config,
 * or core boots against a class that may not be installed. Uninstall this
 * package (or `Features::forceForTesting()` around it) for no social login at
 * all. Existing connections are untouched — this stops new ones and hides
 * the UI.
 *
 * Enabling a provider means credentials in `config/services.php` plus an
 * entry in `numerosis.social.providers`. Google, GitHub, GitLab and Facebook
 * need nothing more; Discord's driver is registered here, since Socialite
 * does not ship one.
 */
class SocialLoginFeature implements NamedFeature
{
    /**
     * Defined as core's constant rather than a literal: three core views gate
     * on this name and cannot reference this class (it may not be installed),
     * so the string has to live somewhere core owns. See
     * {@see ConfiguredProviders::FEATURE}.
     */
    public const NAME = ConfiguredProviders::FEATURE;

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        if (filled(Config::get('services.discord.client_id'))) {
            Event::listen(function (SocialiteWasCalled $event): void {
                $event->extendSocialite('discord', Provider::class);
            });
        }
    }
}
