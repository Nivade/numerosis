<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Providers;

use App\Models\Central\Domain;
use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Http\Middleware\InitializeTenancyByDomainOrSubdomain;
use Nvade\Numerosis\Http\Middleware\NullMiddleware;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

/**
 * Phase 5 of .claude/plans/archive/memoized-tinkering-meadow.md. Covers the parts of
 * IdentificationMode that are provable without a real HTTP request — see
 * .ai/rules/identification-modes.md for what Path mode's route-parameter
 * routing needs that this harness cannot exercise; tests/Browser/PathModeTest
 * is what covers it.
 */
class IdentificationModeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);

        parent::tearDown();
    }

    public function test_default_mode_is_subdomain(): void
    {
        $this->assertSame(IdentificationMode::Subdomain, IdentificationMode::current());
    }

    public function test_each_mode_selects_its_own_identification_middleware(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        $this->assertSame(InitializeTenancyByDomainOrSubdomain::class, TenancyServiceProvider::identificationMiddleware());

        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);
        $this->assertSame(InitializeTenancyByDomain::class, TenancyServiceProvider::identificationMiddleware());

        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Path->value);
        $this->assertSame(InitializeTenancyByPath::class, TenancyServiceProvider::identificationMiddleware());
    }

    /**
     * The Livewire update route carries no `{tenant}` parameter, so
     * `InitializeTenancyByPath` (which asserts `parameterNames()[0] ===
     * 'tenant'`) can't be applied to it under path mode — see
     * `Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath`'s
     * docblock. Every other mode identifies by domain, which needs no route
     * parameter at all, so it reuses `identificationMiddleware()` unchanged.
     */
    public function test_livewire_update_route_uses_a_referer_based_middleware_only_under_path_mode(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        $this->assertSame(InitializeTenancyByDomainOrSubdomain::class, TenancyServiceProvider::livewireUpdateIdentificationMiddleware());

        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);
        $this->assertSame(InitializeTenancyByDomain::class, TenancyServiceProvider::livewireUpdateIdentificationMiddleware());

        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Path->value);
        $this->assertSame(\Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath::class, TenancyServiceProvider::livewireUpdateIdentificationMiddleware());
    }

    public function test_path_mode_skips_the_central_domain_block_and_other_modes_dont(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Path->value);
        $this->assertSame(NullMiddleware::class, TenancyServiceProvider::tenancyRouteMiddleware());

        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        $this->assertNotSame(NullMiddleware::class, TenancyServiceProvider::tenancyRouteMiddleware());

        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);
        $this->assertNotSame(NullMiddleware::class, TenancyServiceProvider::tenancyRouteMiddleware());
    }

    public function test_subdomain_mode_creates_a_domain_row_by_concatenating_the_apex(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        $tenant = Tenant::factory()->create();

        $domain = CreateTenantDomain::run($tenant, 'acme');

        $this->assertInstanceOf(Domain::class, $domain);
        $this->assertSame('acme', $domain->id);
        $this->assertSame('acme.'.Config::string('numerosis.domains.apex'), $domain->domain);
    }

    public function test_custom_domain_mode_stores_the_custom_domain_verbatim(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);
        $tenant = Tenant::factory()->create();

        $domain = CreateTenantDomain::run($tenant, 'acme', 'app.acme.com');

        $this->assertInstanceOf(Domain::class, $domain);
        $this->assertSame('acme', $domain->id);
        $this->assertSame('app.acme.com', $domain->domain);
    }

    public function test_custom_domain_mode_requires_a_custom_domain(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);
        $tenant = Tenant::factory()->create();

        $this->expectException(RuntimeException::class);

        CreateTenantDomain::run($tenant, 'acme');
    }

    public function test_path_mode_creates_no_domain_row_at_all(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Path->value);
        $tenant = Tenant::factory()->create();

        $domain = CreateTenantDomain::run($tenant, 'acme');

        $this->assertNull($domain);
        $this->assertSame(0, $tenant->domains()->count());
    }

    /**
     * The half that would silently pass if the policy checked the wrong
     * table: a `tenants.id` collision with no matching `domains` row must
     * NOT block the subdomain under this mode. A throw here fails the test
     * before the assertion is reached.
     */
    public function test_default_policy_ignores_a_tenant_id_collision_under_subdomain_mode(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        Tenant::factory()->create(['id' => 'occupied-tenant-row-only']);

        resolve(TenantDomainPolicy::class)->assertAvailable('occupied-tenant-row-only');

        $this->assertSame(0, Domain::query()->count(), 'Setup is only meaningful while no domains row exists.');
    }

    public function test_default_policy_rejects_a_taken_subdomain_under_subdomain_mode(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        $tenant = Tenant::factory()->create();
        CreateTenantDomain::run($tenant, 'reserved-slug');

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertAvailable('reserved-slug');
    }

    public function test_default_policy_checks_tenants_table_directly_under_path_mode(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Path->value);
        Tenant::factory()->create(['id' => 'taken-id']);

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertAvailable('taken-id');
    }

    public function test_custom_domain_policy_validates_format_and_uniqueness(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertCustomDomainAvailable('not a domain');
    }

    public function test_custom_domain_policy_rejects_an_already_claimed_domain(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);
        $tenant = Tenant::factory()->create();
        CreateTenantDomain::run($tenant, (string) $tenant->id, 'app.acme.com');

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertCustomDomainAvailable('app.acme.com');
    }

    public function test_tenant_resolves_route_binding_by_id_outside_custom_domain_mode(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        $tenant = Tenant::factory()->create();

        $resolved = $tenant->resolveRouteBinding($tenant->id, 'id');

        $this->assertNotNull($resolved);
        $this->assertSame($tenant->id, $resolved->id);
    }

    /**
     * Under custom-domain mode a tenant is reached by its `domains.domain`
     * row, not by a route binding on `tenants.id`.
     *
     * `Tenant::resolveRouteBinding()` used to carry an override that looked
     * `$field === 'id'` up against `domains.domain`; it existed solely for
     * Filament's panel tenancy and fired for *any* `{tenant:id}` binding,
     * 404ing a valid tenant id with no opt-out. Deleted with
     * `packages/filament`. This asserts the lookup the identification
     * middleware actually performs still works.
     */
    public function test_a_custom_domain_resolves_to_its_tenant_under_that_mode(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);
        $tenant = Tenant::factory()->create();
        CreateTenantDomain::run($tenant, (string) $tenant->id, 'app.acme.com');

        $resolved = Domain::where('domain', 'app.acme.com')->first()?->tenant;

        $this->assertNotNull($resolved);
        $this->assertSame($tenant->id, $resolved->id);
    }

    public function test_reserve_tenant_domain_persists_custom_domain_alongside_the_slug(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::CustomDomain->value);

        \Nvade\Numerosis\Actions\Tenancy\ReserveTenantDomain::run(new \Nvade\Numerosis\Data\Tenancy\TenantRegistrationData(
            company_name: 'Acme',
            domain: 'acme',
            global_id: (string) \Illuminate\Support\Str::uuid(),
            custom_domain: 'app.acme.com',
        ));

        $this->assertDatabaseHas(
            (new PendingTenantProvision)->getTable(),
            ['domain' => 'acme', 'custom_domain' => 'app.acme.com'],
            'central',
        );
    }
}
