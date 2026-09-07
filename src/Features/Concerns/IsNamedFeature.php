<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Concerns;

use Nvade\Numerosis\Support\Features;

/**
 * Supplies both halves of {@see \Nvade\Numerosis\Contracts\NamedFeature} from
 * the implementer's `NAME` constant, so every feature answers "am I on?" the
 * same way and a call site never has to pick between `X::available()` and
 * `Features::enabled(X::NAME)`.
 */
trait IsNamedFeature
{
    public static function featureName(): string
    {
        return static::NAME;
    }

    public static function available(): bool
    {
        return Features::enabled(static::NAME);
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
