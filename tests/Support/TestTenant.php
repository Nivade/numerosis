<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use App\Models\Central\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDatabase;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;

/**
 * A tenant with a working database, built by the configured provisioning
 * steps rather than by a side effect of creating the row.
 *
 * `Tenant::factory()->create()` gives a row and nothing else, which is what a
 * tenant is between the first step and the second. A test that needs to run
 * queries inside the tenant says so by calling this instead.
 *
 * Static, not a trait: PHPStan types `$this` inside a Pest closure as
 * `Pest\PendingCalls\TestCall`, so an instance helper is unreachable from half
 * the suite. Nothing here needs the TestCase anyway.
 */
final class TestTenant
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<ProvisionContribution>  $contributions
     */
    public static function provisioned(
        array $attributes = [],
        ?BaseCentralUser $owner = null,
        array $contributions = [],
    ): BaseTenant {
        $slug = is_string($attributes['id'] ?? null)
            ? $attributes['id']
            : 'tenant'.Str::lower(Str::random(10));

        // No owner means no owner: `AddTenantOwner` records itself skipped,
        // the way it does for a system or imported tenant.
        if ($owner instanceof BaseCentralUser) {
            $contributions = [new OwnerContribution($owner->global_id), ...$contributions];
        }

        resolve(ProvisionsTenant::class)->now(new TenantProvisionData(
            slug: $slug,
            name: self::nameFrom($attributes),
            contributions: $contributions,
        ));

        $tenant = Tenant::findOrFail($slug);

        // Anything the steps do not set: Stripe columns, suspended_at, and the
        // rest of what a factory state would have written.
        $remaining = Arr::except($attributes, ['id', 'name', 'data']);

        if ($remaining !== []) {
            $tenant->forceFill($remaining)->save();
        }

        return $tenant;
    }

    /**
     * A tenant with a working database and nothing past it: no owner, no
     * `provisioned_at`. What a test gets when it runs one of the later steps
     * itself, or asserts on the state between them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function withDatabaseOnly(array $attributes = []): BaseTenant
    {
        $steps = Config::array('numerosis.tenancy.provisioning.steps');

        Config::set('numerosis.tenancy.provisioning.steps', [
            CreateTenant::class,
            CreateTenantDatabase::class,
            CloneTenantSchema::class,
        ]);

        try {
            return TestTenant::provisioned($attributes);
        } finally {
            Config::set('numerosis.tenancy.provisioning.steps', $steps);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function nameFrom(array $attributes): string
    {
        if (is_string($attributes['name'] ?? null)) {
            return $attributes['name'];
        }

        $data = $attributes['data'] ?? null;

        if (is_array($data) && is_string($data['name'] ?? null)) {
            return $data['name'];
        }

        return 'Test Company';
    }
}
