<?php

declare(strict_types=1);

use Rector\Configuration\RectorConfigBuilder;

/**
 * `withSkipPath()` asserts its path exists, so loading the config is itself
 * the check. A path inside `withSkip()` is checked by nothing and goes
 * silently vacuous when the file moves, which is how the `Domains.php` guard
 * sat dead for three days. See `.ai/rules/rector.md`.
 */
test('every path rector is told to skip still exists', function (): void {
    $builder = require dirname(__DIR__, 2).'/rector.php';

    expect($builder)->toBeInstanceOf(RectorConfigBuilder::class);

    $skip = new ReflectionProperty(RectorConfigBuilder::class, 'skip')->getValue($builder);

    expect($skip)->toBeArray();

    /** @var array<array-key, mixed> $skip */
    foreach ($skip as $entry) {
        foreach (is_array($entry) ? $entry : [$entry] as $value) {
            if (! is_string($value) || ! str_starts_with($value, '/') || str_contains($value, '*')) {
                continue;
            }

            expect(file_exists($value))->toBeTrue(
                "rector.php skips {$value}, which does not exist. Move it to withSkipPath() ".
                'so the next move of that file fails the rector run itself.',
            );
        }
    }
});
