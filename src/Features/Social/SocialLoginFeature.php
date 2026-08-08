<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Social;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Contracts\NamedFeature;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * The whole OAuth surface: the oauth/oauth.callback routes, the button grid,
 * the connected-accounts manager, and the two SocialAccount* listeners.
 * Remove this class from config('numerosis.features') and a deployment has no
 * social login at all — no routes, no buttons, no listeners. The
 * socialite_logins table and any rows in it are untouched; disabling stops new
 * connections and hides the UI, it does not delete history.
 *
 * Discord is not a Socialite core driver — it needs the
 * socialiteproviders/discord package registered against SocialiteWasCalled.
 * That registration lives in bootstrap() here rather than as its own feature,
 * gated on credentials being present (config('services.discord.client_id')) —
 * same test Nvade\Numerosis\Support\Social\ConfiguredProviders applies to decide whether
 * to render the button. Google, GitHub, GitLab and Facebook are core drivers
 * and need no registration at all: credentials in config/services.php plus a
 * metadata entry in config('auth.social.providers') is the whole opt-in.
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
