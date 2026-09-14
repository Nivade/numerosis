<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Concerns;

/**
 * Email addresses that cannot collide, for factories backing a table with a
 * unique index on `email`.
 *
 * `fake()->unique()->safeEmail()` does not: `unique()`'s memory lives on the
 * generator instance, which Laravel rebuilds every test. The counter here is
 * per process, and `uniqid()` separates one process from another.
 */
trait GeneratesUniqueEmails
{
    /**
     * Incremented for every address this process hands out.
     */
    protected static int $emailSequence = 0;

    /**
     * Stable for the lifetime of the process, distinct between processes.
     */
    protected static ?string $emailProcessKey = null;

    protected static function uniqueEmail(): string
    {
        static::$emailProcessKey ??= uniqid();

        return sprintf('user%d.%s@example.test', ++static::$emailSequence, static::$emailProcessKey);
    }
}
