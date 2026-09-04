<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The whole OAuth surface: routes, provider buttons, and the connected-
 * accounts manager.
 *
 * Enabling a provider means credentials in `config/services.php` plus a case
 * on `Nvade\Numerosis\Enums\Auth\SocialProvider`. Google, GitHub, GitLab and
 * Facebook need nothing more; Discord's driver is registered here, since
 * Socialite does not ship one. `socialiteproviders/discord` is a `suggest`,
 * so its class names appear here as strings; a `use` import would fatal on a
 * host that has not installed it.
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
        if (! class_exists(\SocialiteProviders\Discord\Provider::class)) {
            return;
        }

        if (filled(Config::get('services.discord.client_id'))) {
            Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event): void {
                $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
            });
        }
    }
}
