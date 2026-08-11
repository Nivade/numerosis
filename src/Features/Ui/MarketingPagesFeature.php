<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Ui;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The marketing pages: terms, privacy, about and features. Turn it off and
 * none of the four routes register.
 *
 * The homepage is deliberately not covered. It is the one route guaranteed
 * to exist on the central domain, which OAuth redirects and checkout error
 * paths rely on. Rename it through `numerosis.routes.names.home` instead.
 */
class MarketingPagesFeature implements NamedFeature
{
    public const NAME = 'ui.marketing';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
