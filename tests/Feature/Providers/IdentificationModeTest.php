<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Providers;

use App\Models\Central\Domain;
use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Actions\Tenancy\ReserveTenantDomain;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath;
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
        $this->useMode(IdentificationMode::Subdomain);

        parent::tearDown();
    }

    /**
     * `IdentificationMode::current()` reads one config key, and everything
     * downstream of it — identification middleware, domain-row creation, the
     * domain policy — reads it through that enum.
     */
    private function useMode(IdentificationMode $mode): void
    {
        Config::set('numerosis.tenancy.identification.mode', $mode->value);
    }

    public function test_default_mode_is_subdomain(): void
    {
        $this->assertSame(IdentificationMode::Subdomain, IdentificationMode::current());
    }

    public function test_each_mode_selects_its_own_identification_middleware(): void
    {
        $this->useMode(IdentificationMode::Subdomain);
        $this->assertSame(InitializeTenancyByDomainOrSubdomain::class, TenancyServiceProvider::identificationMiddleware());

        $this->useMode(IdentificationMode::CustomDomain);
        $this->assertSame(InitializeTenancyByDomain::class, TenancyServiceProvider::identificationMiddleware());

        $this->useMode(IdentificationMode::Path);
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
        $this->useMode(IdentificationMode::Subdomain);
        $this->assertSame(InitializeTenancyByDomainOrSubdomain::class, TenancyServiceProvider::livewireUpdateIdentificationMiddleware());

        $this->useMode(IdentificationMode::CustomDomain);
        $this->assertSame(InitializeTenancyByDomain::class, TenancyServiceProvider::livewireUpdateIdentificationMiddleware());

        $this->useMode(IdentificationMode::Path);
        $this->assertSame(InitializeLivewireTenancyByPath::class, TenancyServiceProvider::livewireUpdateIdentificationMiddleware());
    }

    public function test_path_mode_skips_the_central_domain_block_and_other_modes_dont(): void
    {
        $this->useMode(IdentificationMode::Path);
        $this->assertSame(NullMiddleware::class, TenancyServiceProvider::tenancyRouteMiddleware());

        $this->useMode(IdentificationMode::Subdomain);
        $this->assertNotSame(NullMiddleware::class, TenancyServiceProvider::tenancyRouteMiddleware());

        $this->useMode(IdentificationMode::CustomDomain);
        $this->assertNotSame(NullMiddleware::class, TenancyServiceProvider::tenancyRouteMiddleware());
    }

    public function test_subdomain_mode_creates_a_domain_row_by_concatenating_the_apex(): void
    {
        $this->useMode(IdentificationMode::Subdomain);
        $tenant = Tenant::factory()->create();

        $domain = CreateTenantDomain::run($tenant, 'acme');

        $this->assertInstanceOf(Domain::class, $domain);
        $this->assertSame('acme', $domain->id);
        $this->assertSame('acme.'.Config::string('numerosis.domains.apex'), $domain->domain);
    }

    public function test_custom_domain_mode_stores_the_custom_domain_verbatim(): void
    {
        $this->useMode(IdentificationMode::CustomDomain);
        $tenant = Tenant::factory()->create();

        $domain = CreateTenantDomain::run($tenant, 'acme', 'app.acme.com');

        $this->assertInstanceOf(Domain::class, $domain);
        $this->assertSame('acme', $domain->id);
        $this->assertSame('app.acme.com', $domain->domain);
    }

    public function test_custom_domain_mode_requires_a_custom_domain(): void
    {
        $this->useMode(IdentificationMode::CustomDomain);
        $tenant = Tenant::factory()->create();

        $this->expectException(RuntimeException::class);

        CreateTenantDomain::run($tenant, 'acme');
    }

    public function test_path_mode_creates_no_domain_row_at_all(): void
    {
        $this->useMode(IdentificationMode::Path);
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
        $this->useMode(IdentificationMode::Subdomain);
        Tenant::factory()->create(['id' => 'occupied-tenant-row-only']);

        resolve(TenantDomainPolicy::class)->assertAvailable('occupied-tenant-row-only');

        $this->assertSame(0, Domain::query()->count(), 'Setup is only meaningful while no domains row exists.');
    }

    public function test_default_policy_rejects_a_taken_subdomain_under_subdomain_mode(): void
    {
        $this->useMode(IdentificationMode::Subdomain);
        $tenant = Tenant::factory()->create();
        CreateTenantDomain::run($tenant, 'reserved-slug');

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertAvailable('reserved-slug');
    }

    public function test_default_policy_checks_tenants_table_directly_under_path_mode(): void
    {
        $this->useMode(IdentificationMode::Path);
        Tenant::factory()->create(['id' => 'taken-id']);

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertAvailable('taken-id');
    }

    public function test_custom_domain_policy_validates_format_and_uniqueness(): void
    {
        $this->useMode(IdentificationMode::CustomDomain);

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertCustomDomainAvailable('not a domain');
    }

    public function test_custom_domain_policy_rejects_an_already_claimed_domain(): void
    {
        $this->useMode(IdentificationMode::CustomDomain);
        $tenant = Tenant::factory()->create();
        CreateTenantDomain::run($tenant, (string) $tenant->id, 'app.acme.com');

        $this->expectException(ValidationException::class);
        resolve(TenantDomainPolicy::class)->assertCustomDomainAvailable('app.acme.com');
    }

    public function test_tenant_resolves_route_binding_by_id_outside_custom_domain_mode(): void
    {
        $this->useMode(IdentificationMode::Subdomain);
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
        $this->useMode(IdentificationMode::CustomDomain);
        $tenant = Tenant::factory()->create();
        CreateTenantDomain::run($tenant, (string) $tenant->id, 'app.acme.com');

        $resolved = Domain::where('domain', 'app.acme.com')->first()?->tenant;

        $this->assertNotNull($resolved);
        $this->assertSame($tenant->id, $resolved->id);
    }

    public function test_reserve_tenant_domain_persists_custom_domain_alongside_the_slug(): void
    {
        $this->useMode(IdentificationMode::CustomDomain);

        ReserveTenantDomain::run(new TenantRegistrationData(
            company_name: 'Acme',
            domain: 'acme',
            global_id: (string) Str::uuid(),
            custom_domain: 'app.acme.com',
        ));

        $this->assertDatabaseHas(
            (new PendingTenantProvision)->getTable(),
            ['domain' => 'acme', 'custom_domain' => 'app.acme.com'],
            'central',
        );
    }
}
