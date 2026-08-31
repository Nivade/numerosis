<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Concerns;

use Filament\Notifications\Notification;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;

/**
 * The success, warning and error notifications the panels send.
 *
 * Use {@see self::notifyDomainError()} for anything reporting an exception.
 * It accepts only {@see ShowsMessageToUser}, the one exception type whose
 * message is written for a user — so an unexpected error cannot leak a SQL
 * fragment or Stripe internals into the browser.
 *
 * Static, so they can be called from Filament's static resource closures as
 * well as from pages.
 */
trait NotifiesUser
{
    /**
     * Report a domain-expected failure using the exception's own copy.
     */
    public static function notifyDomainError(ShowsMessageToUser $e): void
    {
        static::notifyError($e->getMessage());
    }

    /**
     * Report a failure with a message written for the user.
     *
     * Pass translated copy or an exception's own message — never a raw
     * `Throwable::getMessage()` from an exception this app did not define.
     */
    public static function notifyError(string $body): void
    {
        Notification::make()
            ->title('Error')
            ->body($body)
            ->danger()
            ->send();
    }

    public static function notifySuccess(string $title, ?string $body = null): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }
}
