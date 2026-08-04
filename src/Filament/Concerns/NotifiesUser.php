<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Concerns;

use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Filament\Notifications\Notification;

/**
 * The three Filament notifications this app actually sends.
 *
 * Roughly twenty hand-built `Notification::make()` chains existed across the
 * panel pages, resources and the module traits, and the error variant was the
 * same five lines every time. Collapsing them matters less for the line count
 * than for {@see notifyDomainError()}: that method is typed against
 * {@see ShowsMessageToUser}, which is the only exception type allowed to reach
 * a user verbatim (.claude/rules/exception-handling.md). Every call site used
 * to re-state that rule in its own `catch`, so getting it right depended on
 * each author remembering it. Now the signature enforces it — a `Throwable`
 * will not type-check, so an unexpected error cannot leak a SQL fragment or
 * Stripe internals into the browser.
 *
 * The methods are `static` because roughly half the call sites are inside
 * Filament's static `Resource::table()` closures, where there is no `$this`.
 * None of them touch instance state, and PHP allows `$this->notifySuccess(…)`
 * on a static method, so page classes read unchanged.
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
