<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Ui;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The account UI: settings (profile, password, appearance), the tenant list,
 * the billing portal and invoice downloads.
 *
 * Product surface rather than framework — turn it off and build your own.
 * The password settings page also needs password resets enabled.
 */
class AccountPagesFeature implements NamedFeature
{
    public const NAME = 'ui.account';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
