<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Illuminate\Support\Facades\Config;
use LogicException;
use Nvade\Numerosis\Contracts\Feature;
use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * Answers "is this feature enabled?" from `config('numerosis.features')` —
 * the single place features are switched on and off.
 *
 * Route files, service providers and Blade views all need that answer at
 * points where the feature-boot loop has not run or has already finished,
 * which is why it is asked here rather than tracked as boot state.
 *
 * In tests, {@see self::forceForTesting()} overrides the config, and must be
 * called before the application boots — routes are registered during boot.
 */
final class Features
{
    /** @var list<class-string<Feature>>|null */
    private static ?array $forcedForTesting = null;

    /** @var array<string, class-string<Feature>>|null */
    private static ?array $nameMap = null;

    /**
     * Features contributed via {@see self::register()}, kept separate from
     * `config('numerosis.features')` so a second package can add itself
     * without the host editing that array by hand.
     *
     * @var list<class-string<Feature>>
     */
    private static array $registered = [];

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

        return array_values(array_unique([...$features, ...self::$registered]));
    }

    /**
     * Register a feature outside `config('numerosis.features')` — the seam
     * a second package uses to contribute its own {@see Feature} without the
     * host having to edit that array. Duplicate registration (or a class
     * already listed in config) is a no-op, not a second `bootstrap()` call.
     *
     * @param  class-string<Feature>  $feature
     */
    public static function register(string $feature): void
    {
        if (! in_array($feature, self::$registered, true)) {
            self::$registered[] = $feature;
            self::$nameMap = null;
        }
    }

    /**
     * Clears {@see self::register()} contributions. For tests only — a real
     * host/package registers once and it lives for the application's
     * lifetime.
     */
    public static function resetRegisteredForTesting(): void
    {
        self::$registered = [];
        self::$nameMap = null;
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
