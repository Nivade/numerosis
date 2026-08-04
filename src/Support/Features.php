<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Nvade\Numerosis\Contracts\Feature;
use Nvade\Numerosis\Contracts\NamedFeature;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * Reads config('numerosis.features') — the one place a feature is switched on
 * or off — and answers questions the boot loop cannot: route files, service
 * providers and Blade all need "is this on?" at moments the loop has already
 * run or has not run yet.
 *
 * $forcedForTesting is a plain static, not container-scoped, so it survives a
 * fresh Application boot within the same PHP process (same trap
 * .claude/rules/testing.md documents for Tenant::unsetEventDispatcher()).
 * A test that forces features must set them BEFORE parent::setUp(), because
 * routes are registered while the application boots — see the reset note in
 * Tests\TestCase::setUp().
 */
final class Features
{
    /** @var list<class-string<Feature>>|null */
    private static ?array $forcedForTesting = null;

    /** @var array<string, class-string<Feature>>|null */
    private static ?array $nameMap = null;

    /**
     * @return list<class-string<Feature>>
     */
    public static function all(): array
    {
        if (self::$forcedForTesting !== null) {
            return self::$forcedForTesting;
        }

        /** @var list<class-string<Feature>> $features */
        $features = Config::array('numerosis.features');

        return $features;
    }

    /**
     * @param  class-string<Feature>  $class
     */
    public static function enabledClass(string $class): bool
    {
        return in_array($class, self::all(), true);
    }

    public static function enabled(string $name): bool
    {
        return isset(self::names()[$name]);
    }

    /**
     * Built once per resolved feature list rather than scanned per call —
     * enabled() is called from inside Blade loops.
     *
     * @return array<string, class-string<Feature>>
     */
    private static function names(): array
    {
        if (self::$nameMap !== null) {
            return self::$nameMap;
        }

        $map = [];

        foreach (self::all() as $class) {
            if (! is_a($class, NamedFeature::class, true)) {
                continue;
            }

            $name = $class::featureName();

            if (isset($map[$name])) {
                throw new LogicException(
                    "Two features claim the name [{$name}]: [{$map[$name]}] and [{$class}].",
                );
            }

            $map[$name] = $class;
        }

        return self::$nameMap = $map;
    }

    /**
     * @param  list<class-string<Feature>>|null  $features  null restores config
     */
    public static function forceForTesting(?array $features): void
    {
        self::$forcedForTesting = $features;
        self::$nameMap = null;
    }
}
