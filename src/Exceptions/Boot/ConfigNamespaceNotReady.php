<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Boot;

use RuntimeException;

/**
 * A config namespace was written into before the package owning it had merged
 * its defaults. Not user-facing: it means this package booted too early.
 */
final class ConfigNamespaceNotReady extends RuntimeException
{
    public static function for(string $key, string $namespace): self
    {
        return new self(
            "Refusing to write config key [{$key}]: [{$namespace}] is not an array yet, so the write would "
            ."truncate it and the owning package's own defaults would then be discarded by its mergeConfigFrom().",
        );
    }
}
