<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts;

/**
 * A Feature that other code can ask about by name.
 *
 * The toggle is still presence of the class in config('numerosis.features') —
 * this interface only gives that presence a stable name so route files,
 * providers and Blade can check it without importing the class everywhere.
 * Implementers declare `public const NAME = '…'` and return it here.
 *
 * Features::enabled() takes a plain string, so nothing *forces* a call site to
 * pass SomeFeature::NAME — passing a literal that no feature declares is a
 * silently-false check, not an error. Convention only: always pass the
 * constant. The one thing that is enforced is that two features cannot share
 * a name (Features::names() throws on a collision).
 */
interface NamedFeature extends Feature
{
    public static function featureName(): string;
}
