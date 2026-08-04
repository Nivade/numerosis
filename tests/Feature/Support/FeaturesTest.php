<?php

declare(strict_types=1);

use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Support\Features;

test('it reports a feature listed in config as enabled', function (): void {
    Features::forceForTesting([TurnstileFeature::class]);

    expect(Features::enabled(TurnstileFeature::NAME))->toBeTrue()
        ->and(Features::enabledClass(TurnstileFeature::class))->toBeTrue();
});

test('it reports an unlisted feature as disabled', function (): void {
    Features::forceForTesting([]);

    expect(Features::enabled(TurnstileFeature::NAME))->toBeFalse()
        ->and(Features::enabledClass(TurnstileFeature::class))->toBeFalse();
});

test('it falls back to config when nothing is forced', function (): void {
    Features::forceForTesting(null);

    expect(Features::all())->toBe(config('numerosis.features'));
});

test('it clears the memoised name map when the override changes', function (): void {
    Features::forceForTesting([TurnstileFeature::class]);
    expect(Features::enabled(TurnstileFeature::NAME))->toBeTrue();

    Features::forceForTesting([]);

    expect(Features::enabled(TurnstileFeature::NAME))->toBeFalse();
});
