<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin;

use App\Models\Central\CentralUser as User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Filament\Admin\Pages\RegisterTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * RegisterTenant::$layout pointed at 'components.layouts.app.none', a view
 * that doesn't exist — MissingLayoutException on every load. Went unnoticed
 * because nothing linked to this page (TenantResource's own broken
 * CreateRecord page was the "New Tenant" button's target) and no test hit
 * the route directly; ListTenants::getHeaderActions() now links here, which
 * is what surfaced it. The correct reference, 'layouts::app.none', is what
 * the wizard this page embeds already uses correctly.
 */
class RegisterTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::before(fn () => true);
    }

    public function test_it_renders_without_a_missing_layout_error(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(RegisterTenant::getUrl())->assertSuccessful();
    }

    /**
     * layouts::app.none (the layout this page uses) carried @fluxScripts but
     * no @livewireScripts — every wire:click on the page, including the
     * wizard's "Continue" button, was a dead click with no console error,
     * since Livewire's JS runtime never loaded at all. Asserting the actual
     * script tag is present, not just a successful HTTP status, since 200
     * alone doesn't prove the page is actually interactive.
     */
    public function test_it_loads_the_livewire_javascript_runtime(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(RegisterTenant::getUrl())
            ->assertSuccessful()
            ->assertSee('livewire.js', escape: false);
    }
}
