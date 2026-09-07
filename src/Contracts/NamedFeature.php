<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts;

/**
 * A Feature route files, providers and Blade can ask about by name without
 * importing its class. Implementers declare `public const NAME = '…'` and
 * return it here; always pass that constant to `FeatureRegistry::enabled()`, which
 * takes a plain string and reads an undeclared literal as a silently-false
 * check. Two features sharing a name is the one case `FeatureRegistry::names()` throws on.
 */
interface NamedFeature extends Feature
{
    public static function featureName(): string;

    public static function available(): bool;
}
