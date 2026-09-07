<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Support\FeatureRegistry;

test('it reports a feature listed in config as enabled', function (): void {
    FeatureRegistry::forceForTesting([TurnstileFeature::class]);

    expect(FeatureRegistry::enabled(TurnstileFeature::NAME))->toBeTrue()
        ->and(FeatureRegistry::enabledClass(TurnstileFeature::class))->toBeTrue();
});

test('it reports an unlisted feature as disabled', function (): void {
    FeatureRegistry::forceForTesting([]);

    expect(FeatureRegistry::enabled(TurnstileFeature::NAME))->toBeFalse()
        ->and(FeatureRegistry::enabledClass(TurnstileFeature::class))->toBeFalse();
});

test('it falls back to config when nothing is forced, plus anything a satellite package registered', function (): void {
    FeatureRegistry::forceForTesting(null);

    // Not `toBe(config(...))`: since Phase 3's `FeatureRegistry::register()` seam,
    // `all()` is the union of the host's config list and what installed
    // satellite packages contributed at register time — nvade/numerosis-auth-ui
    // adds its own SocialLoginFeature that way, precisely so core's config
    // never names a class that may not be installed.
    /** @var list<class-string<Nvade\Numerosis\Contracts\Feature>> $configured */
    $configured = Config::array('numerosis.features');

    expect(FeatureRegistry::all())
        ->toEqualCanonicalizing([...$configured, ...FeatureRegistry::registered()])
        ->and(FeatureRegistry::all())->toContain(...$configured);
});

test('it clears the memoised name map when the override changes', function (): void {
    FeatureRegistry::forceForTesting([TurnstileFeature::class]);
    expect(FeatureRegistry::enabled(TurnstileFeature::NAME))->toBeTrue();

    FeatureRegistry::forceForTesting([]);

    expect(FeatureRegistry::enabled(TurnstileFeature::NAME))->toBeFalse();
});
