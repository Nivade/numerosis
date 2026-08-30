<?php

declare(strict_types=1);

/**
 * PHPStan-only reflection stubs for the `stancl/tenancy:^3.10` ("v3") symbols
 * this package's `Support\Compat\Tenancy\*` shims and
 * `Support\Tenancy\TenancyVersion` reference inside their **v3** branch.
 *
 * This is the exact mirror of `.phpstan/stancl-tenancy-dev-master.stub.php`,
 * and the two are **mutually exclusive** — each leg of the matrix loads only
 * the stub for the version that is *not* installed:
 *
 * | installed          | config                        | stub loaded          |
 * |--------------------|-------------------------------|----------------------|
 * | `^3.10` (stable)   | `phpstan.neon.dist`           | the dev-master stub  |
 * | `dev-master`       | `phpstan-dev-master.neon.dist`| this file            |
 *
 * Loading both at once is what the split exists to prevent: a `scanFiles`
 * stub **shadows** the real class rather than merging with it, so with
 * dev-master installed the dev-master stub's deliberately-trimmed
 * `Stancl\Tenancy\Database\DatabaseConfig` replaced the real one and every
 * `->database()->getName()` / `->manager()` call in this package reported as
 * `method.notFound` — five call sites, none of them defects.
 *
 * Shapes are copied verbatim from the real `v3.10.1` tag
 * (`git clone --depth 1 --branch v3.10.1 https://github.com/archtechx/tenancy.git /tmp/v3`),
 * trimmed to the members this package actually touches, and are deliberately
 * as loose as v3's own source — several of these methods carry no return type
 * at all there. Do not "improve" them: a stricter stub than the real class
 * makes the stable leg's own analysis wrong in the opposite direction.
 */

namespace Stancl\Tenancy {

    use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

    /**
     * v3 keeps `DatabaseConfig` in the root namespace; dev-master moved it to
     * `Stancl\Tenancy\Database\DatabaseConfig`. Only the v3 location is
     * stubbed here — the dev-master one is a real class when this file is in
     * use, and stubbing it is precisely the bug described above.
     */
    class DatabaseConfig
    {
        // Nullable on v3 and non-nullable on dev-master. Copy v3's real
        // signature exactly — declaring it `string` here makes this package's
        // own `?? throw` and `!== null` guards read as dead code on this leg,
        // which is the opposite of the truth.
        public function getName(): ?string {}

        public function manager(): Contracts\TenantDatabaseManager {}
    }

    class UUIDGenerator implements UniqueIdentifierGenerator
    {
        /** @param mixed $resource */
        public static function generate($resource): string {}
    }
}

namespace Stancl\Tenancy\Contracts {

    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Stancl\Tenancy\DatabaseConfig;

    interface Syncable
    {
        public function getGlobalIdentifierKeyName(): string;

        public function getGlobalIdentifierKey();

        public function getCentralModelName(): string;

        /** @return array<int|string, mixed> */
        public function getSyncedAttributeNames(): array;

        public function triggerSyncEvent();
    }

    /**
     * @property-read Tenant[]|Collection<int, Tenant> $tenants
     */
    interface SyncMaster extends Syncable
    {
        // No generic template on the return type, matching v3's own source —
        // see the same note on the dev-master stub's SyncMaster for why a
        // stricter one breaks every implementor.
        public function tenants(): BelongsToMany;

        public function getTenantModelName(): string;
    }

    interface TenantWithDatabase extends Tenant
    {
        public function database(): DatabaseConfig;

        /** Get an internal key. */
        public function getInternal(string $key);
    }
}

namespace Stancl\Tenancy\Database\Concerns {

    trait ResourceSyncing
    {
        public static function bootResourceSyncing() {}

        public function triggerSyncEvent() {}
    }
}

namespace Stancl\Tenancy\Database\Models {

    class TenantPivot extends \Illuminate\Database\Eloquent\Relations\Pivot
    {
        public static function boot() {}
    }
}

namespace Stancl\Tenancy\Events {

    use Illuminate\Database\Eloquent\Model;
    use Stancl\Tenancy\Contracts\Syncable;
    use Stancl\Tenancy\Contracts\TenantWithDatabase;

    class SyncedResourceSaved
    {
        /** @var Syncable|Model */
        public $model;

        /** @var TenantWithDatabase|Model|null */
        public $tenant;

        public function __construct(Syncable $model, ?TenantWithDatabase $tenant) {}
    }

    class SyncedResourceChangedInForeignDatabase
    {
        /** @var Syncable|Model */
        public $model;

        /** @var TenantWithDatabase|Model|null */
        public $tenant;

        public function __construct(Syncable $model, ?TenantWithDatabase $tenant) {}
    }
}

namespace Stancl\Tenancy\Listeners {

    use Stancl\Tenancy\Events\SyncedResourceSaved;

    class UpdateSyncedResource extends QueueableListener
    {
        /** @var bool */
        public static $shouldQueue = false;

        public function handle(SyncedResourceSaved $event) {}
    }
}

namespace Stancl\Tenancy\Middleware {

    use Closure;
    use Illuminate\Http\Request;

    class PreventAccessFromCentralDomains
    {
        /** @var callable|null */
        public static $abortRequest;

        public function handle(Request $request, Closure $next) {}
    }
}

namespace Stancl\Tenancy\Concerns {

    use Illuminate\Support\LazyCollection;

    trait HasATenantsOption
    {
        public function __construct() {}

        /** @return array<int, mixed> */
        protected function getOptions() {}

        /** @return LazyCollection<int, \Stancl\Tenancy\Contracts\Tenant> */
        protected function getTenants(): LazyCollection {}
    }
}
