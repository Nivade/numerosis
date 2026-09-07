<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * The whole OAuth surface: routes, provider buttons, connected-accounts
 * manager. Enabling a provider means credentials in `config/services.php` plus
 * a case on `Enums\Auth\SocialProvider`. Discord also needs its driver
 * registered here.
 */
class SocialLoginFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'social';

    public function bootstrap(): void
    {
        if (filled(Config::get('services.discord.client_id'))) {
            Event::listen(function (SocialiteWasCalled $event): void {
                $event->extendSocialite('discord', DiscordProvider::class);
            });
        }
    }
}
