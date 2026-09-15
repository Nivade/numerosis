<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * The host adapter `TenantDatabaseManager` exists for: it answers without a
 * database server, which is what lets `CreateTenantDatabase`'s two branches be
 * tested at all.
 */
final class FakeTenantDatabaseManager implements TenantDatabaseManager
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $exists) {}

    public function databaseExists(TenantWithDatabase $tenant): bool
    {
        $this->asked[] = (string) $tenant->getTenantKey();

        return $this->exists;
    }
}
