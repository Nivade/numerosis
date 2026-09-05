<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Auth;

use Illuminate\Support\Facades\Config;

/**
 * The social providers core knows, and the single source of truth for which
 * are actually usable. Replaces `numerosis.social.providers`, which held the
 * same three facts (label, icon, "is a client id present") as a config array
 * a host had to keep in sync by hand.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Discord = 'discord';
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Discord => 'Discord',
            self::Facebook => 'Facebook',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Google, self::Facebook => 'globe-alt',
            self::GitHub, self::GitLab => 'code-bracket',
            self::Discord => 'chat-bubble-left-right',
        };
    }

    /**
     * `client_id` specifically, never membership in `array_keys(config('services'))`
     * That config file declares `client_id` for every provider unconditionally
     * (`env('GOOGLE_CLIENT_ID', '')`), and also holds unrelated services
     * (postmark, ses, resend, slack, turnstile) that would otherwise match.
     */
    public function isConfigured(): bool
    {
        return filled(Config::get("services.{$this->value}.client_id"));
    }

    /**
     * @return list<string>
     */
    public static function configuredValues(): array
    {
        return array_map(fn (self $provider): string => $provider->value, self::configured());
    }

    /**
     * @return list<self>
     */
    public static function configured(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider): bool => $provider->isConfigured()));
    }
}
