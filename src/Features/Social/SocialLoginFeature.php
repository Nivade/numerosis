<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Social;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Contracts\NamedFeature;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * The whole OAuth surface: routes, provider buttons, and the connected-
 * accounts manager.
 *
 * Remove it from `numerosis.features` for no social login at all. Existing
 * connections are untouched — this stops new ones and hides the UI.
 *
 * Enabling a provider means credentials in `config/services.php` plus an
 * entry in `numerosis.social.providers`. Google, GitHub, GitLab and Facebook
 * need nothing more; Discord's driver is registered here, since Socialite
 * does not ship one.
 */
class SocialLoginFeature implements NamedFeature
{
    public const NAME = 'social';

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
