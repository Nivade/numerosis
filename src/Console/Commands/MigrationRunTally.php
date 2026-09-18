<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

/**
 * A migration run's counters, scoped to one `MigrateTenants::handle()` call
 * instead of living as command properties that need resetting by hand on
 * reuse.
 */
final class MigrationRunTally
{
    /** @var array<string, string> */
    private array $failures = [];

    private int $migrated = 0;

    private int $skipped = 0;

    public function migrated(): void
    {
        $this->migrated++;
    }

    public function skipped(): void
    {
        $this->skipped++;
    }

    public function failed(string $tenantId, string $error): void
    {
        $this->failures[$tenantId] = $error;
    }

    public function migratedCount(): int
    {
        return $this->migrated;
    }

    public function skippedCount(): int
    {
        return $this->skipped;
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
