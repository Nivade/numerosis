<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament;

use App\Models\Central\CentralUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Filament\Admin\Pages\RegisterTenant;
use Nvade\Numerosis\Filament\NumerosisTenantPlugin;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionMethod;

/**
 * Phase 6/D1 of `.claude/plans/memoized-tinkering-meadow.md`: the two edges
 * that stopped the Filament layer being a leaf, now config-bound so it
 * depends on core alone.
 *
 * Both edges fail *late* without these seams, which is why they are tested at
 * the seam rather than through the UI: `->login(Foo::class)` takes a
 * compile-time string, so a missing login component registers cleanly and
 * only fatals at the first `/login` on a tenant subdomain; and a missing
 * wizard leaves a routable page whose whole body is an `@livewire()` for a
 * component Livewire cannot find.
 */
class PanelUiSeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tenant_panel_login_defaults_to_the_shipped_passwordless_component(): void
    {
        $this->assertSame(
            \Nvade\Numerosis\Livewire\Auth\PasswordlessLogin::class,
            $this->loginComponent()
        );
    }

    public function test_it_skips_the_login_wiring_when_no_component_is_configured(): void
    {
        Config::set('numerosis.panels.tenant.login', null);

        $this->assertNull($this->loginComponent());
    }

    /**
     * The guard that matters: a configured class that isn't installed must
     * read as "no login page", not be handed to Filament to fatal on later.
     */
    public function test_it_skips_a_configured_login_component_that_is_not_installed(): void
    {
        Config::set('numerosis.panels.tenant.login', 'Acme\\Auth\\NotInstalledLogin');

        $this->assertNull($this->loginComponent());
    }

    public function test_the_register_tenant_page_defaults_to_the_shipped_wizard_alias(): void
    {
        $this->assertSame('tenant-registration', RegisterTenant::wizardComponent());
    }

    public function test_the_register_tenant_page_is_unreachable_when_no_wizard_is_configured(): void
    {
        Config::set('numerosis.panels.admin.tenant_registration_component', null);
        Gate::before(fn (): bool => true);

        $this->actingAs(User::factory()->create());

        $this->assertNull(RegisterTenant::wizardComponent());
        $this->assertFalse(RegisterTenant::canAccess());
        $this->assertFalse(RegisterTenant::shouldRegisterNavigation());
    }

    /**
     * The seam only ever *narrows*: with a wizard configured it must fall
     * through to whatever the page would otherwise have answered. Note
     * `Filament\Pages\Page::canAccess()` is unconditionally `true` — this
     * page carries no policy of its own — so "defers to the parent" and
     * "returns true" are the same assertion here, and a future policy on
     * this page is what would make them differ.
     */
    public function test_a_configured_wizard_leaves_the_pages_own_access_answer_alone(): void
    {
        Gate::before(fn (): bool => true);

        $this->actingAs(User::factory()->create());

        $this->assertNotNull(RegisterTenant::wizardComponent());
        $this->assertTrue(RegisterTenant::canAccess());
        $this->assertTrue(RegisterTenant::shouldRegisterNavigation());
    }

    private function loginComponent(): ?string
    {
        $method = new ReflectionMethod(NumerosisTenantPlugin::class, 'loginComponent');

        $component = $method->invoke(null);

        return is_string($component) ? $component : null;
    }
}
