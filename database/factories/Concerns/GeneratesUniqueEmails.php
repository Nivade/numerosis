<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Concerns;

/**
 * Email addresses that cannot collide, for factories backing a table with a
 * unique index on `email`.
 *
 * `fake()->unique()->safeEmail()` looks like it already guarantees this and
 * does not: `unique()`'s memory lives on the generator instance, which Laravel
 * rebuilds for every test, while `safeEmail()` draws from a pool of a few
 * thousand values. Over a full suite the same address comes up repeatedly —
 * harmless while each test starts from an empty table, and a
 * `users.users_email_unique` violation the moment one does not. Under
 * `--parallel` that surfaced as a ~50% flake rate, with a different test and a
 * different address each run.
 *
 * The counter is per process, and `uniqid()` separates one process from
 * another, so parallel workers sharing a database would still not collide.
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
