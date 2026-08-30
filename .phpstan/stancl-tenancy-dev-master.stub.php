<?php

declare(strict_types=1);

/**
 * PHPStan-only reflection stubs for the `stancl/tenancy:dev-master` ("v4")
 * symbols this package's `Support\Compat\Tenancy\*` shims reference inside
 * their `TenancyVersion::isDevMaster()` branch.
 *
 * This file is never autoloaded and never shipped to a consumer — it exists
 * solely so PHPStan can resolve both branches of each shim's conditional
 * class/interface/trait declaration when only `v3.10.1` is actually
 * installed (see `phpstan.neon.dist`'s `scanFiles`). Without it, PHPStan
 * reports every dev-master symbol as `class.notFound`/`interface.notFound`/
 * `trait.notFound`, and that unresolved type cascades into unrelated
 * `method.notFound`/`generics.notSubtype` errors anywhere a shim-typed value
 * flows — e.g. `Membership extends Support\Compat\Tenancy\TenantPivot`
 * turning every `BelongsToMany<..., Membership, ...>` generic invalid.
 *
 * Shapes are copied from a real `dev-master` checkout
 * (`git clone --depth 1 --branch master https://github.com/archtechx/tenancy.git /tmp/v4`,
 * see `.claude/rules/stancl-tenancy-v4.md`), trimmed to the members this
 * package actually calls. Re-verify against a fresh checkout before trusting
 * a signature here over the real source.
 */

namespace Stancl\Tenancy\ResourceSyncing {

    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

    interface Syncable
    {
        public function getGlobalIdentifierKeyName(): string;

        public function getGlobalIdentifierKey(): string|int;

        public function getCentralModelName(): string;

        /** @return array<int|string, mixed> */
        public function getSyncedAttributeNames(): array;

        public function triggerSyncEvent(): void;

        public function triggerDeleteEvent(bool $forceDelete = false): void;

        /** @return array<int|string, mixed> */
        public function getCreationAttributes(): array;

        public function shouldSync(): bool;
    }

    /**
     * @property-read TenantWithDatabase[]|Collection<int, TenantWithDatabase&Model> $tenants
     */
    interface SyncMaster extends Syncable
    {
        // No PHPDoc generic template on tenants() here, deliberately — same
        // looseness as v3's real interface, which declares no `@return`
        // template at all. A strict generic here would force every
        // implementor's own more specific `BelongsToMany<..., Membership,
        // 'pivot'>` return type to satisfy it, and PHPStan resolves this
        // conditionally-declared interface to one canonical branch
        // regardless of which real version is installed (see
        // `Nvade\Numerosis\Listeners\Tenancy\UpdateSyncedResource`'s
        // docblock for the same flattening behaviour elsewhere).
        public function tenants(): BelongsToMany;

        public function getTenantModelName(): string;

        public function triggerDetachEvent(TenantWithDatabase&Model $tenant): void;

        public function triggerAttachEvent(TenantWithDatabase&Model $tenant): void;

        public function triggerRestoreEvent(): void;
    }

    trait ResourceSyncing
    {
        // getCentralModelName()/getSyncedAttributeNames()/getTenantModelName()
        // are NOT provided by the real trait — Syncable/SyncMaster leave
        // them for the consuming model, matching v3's ResourceSyncing.
        public function getGlobalIdentifierKeyName(): string
        {
        }

        public function getGlobalIdentifierKey(): string|int
        {
        }

        public function triggerSyncEvent(): void
        {
        }

        public function triggerDeleteEvent(bool $forceDelete = false): void
        {
        }

        public function triggerAttachEvent(TenantWithDatabase&Model $tenant): void
        {
        }

        public function triggerDetachEvent(TenantWithDatabase&Model $tenant): void
        {
        }

        public function triggerRestoreEvent(): void
        {
        }

        /** @return array<int|string, mixed> */
        public function getCreationAttributes(): array
        {
        }

        public function shouldSync(): bool
        {
        }

        public function tenants(): BelongsToMany
        {
        }
    }

    class TenantPivot extends \Illuminate\Database\Eloquent\Relations\Pivot {}

    interface PivotWithCentralResource
    {
        public function getCentralResourceClass(): string;
    }
}

namespace Stancl\Tenancy\ResourceSyncing\Events {

    use Illuminate\Database\Eloquent\Model;
    use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
    use Stancl\Tenancy\ResourceSyncing\Syncable;

    class SyncedResourceSaved
    {
        public function __construct(
            public Syncable&Model $model,
            public TenantWithDatabase|null $tenant,
        ) {}
    }

    class SyncedResourceSavedInForeignDatabase
    {
        public function __construct(
            public Syncable&Model $model,
            public TenantWithDatabase|null $tenant,
        ) {}
    }
}

namespace Stancl\Tenancy\ResourceSyncing\Listeners {

    use Stancl\Tenancy\Listeners\QueueableListener;
    use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSaved;

    class UpdateOrCreateSyncedResource extends QueueableListener
    {
        public static bool $shouldQueue = false;

        public function handle(SyncedResourceSaved $event): void
        {
        }
    }
}

namespace Stancl\Tenancy\Database\Contracts {

    use Stancl\Tenancy\Contracts\Tenant;
    use Stancl\Tenancy\Database\DatabaseConfig;

    interface TenantWithDatabase extends Tenant
    {
        public function database(): DatabaseConfig;
    }
}

namespace Stancl\Tenancy\Database {

    class DatabaseConfig extends \Stancl\Tenancy\DatabaseConfig {}
}

namespace Stancl\Tenancy\Concerns {

    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Support\LazyCollection;

    trait HasTenantOptions
    {
        public function __construct(mixed ...$args)
        {
        }

        /** @return LazyCollection<int, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> */
        protected function getTenants(?array $tenantKeys = null): LazyCollection
        {
        }

        /** @return Builder<\Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> */
        protected function getTenantsQuery(?array $tenantKeys = null): Builder
        {
        }
    }
}

namespace Stancl\Tenancy\Middleware {

    class PreventAccessFromUnwantedDomains {}
}

namespace Stancl\Tenancy\UniqueIdentifierGenerators {

    class UUIDGenerator implements \Stancl\Tenancy\Contracts\UniqueIdentifierGenerator
    {
        public static function generate(\Illuminate\Database\Eloquent\Model $model): string|int
        {
        }
    }
}

namespace Stancl\Tenancy\Enums {

    enum RouteMode
    {
        case CENTRAL;
        case TENANT;
        case UNIVERSAL;
    }
}
