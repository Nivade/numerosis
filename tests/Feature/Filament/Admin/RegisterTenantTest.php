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

    /**
     * This page's view used to be a bare `@livewire('tenant-registration')`
     * — its entire render output was that one directive, nothing else.
     * Livewire collapsed the wizard's own component boundary into this
     * page's: the rendered DOM carried a wire:id for this page and one for
     * whichever step was current, never a third for the wizard itself. Since
     * every step's "Continue"/"Back" dispatches its transition event
     * `->to('tenant-registration')`, and no live component was ever
     * registered under that name, the event had nowhere to land — 200 OK,
     * no console error, no server exception, the wizard just silently never
     * advanced past step one. Fixed by wrapping the directive in a `<div>`,
     * which is enough to stop Livewire flattening the two components
     * together. Asserting on the wire:id count directly (not on step
     * advancement, which needs a real browser to click through) since that's
     * the exact mechanical fact that broke.
     */
    public function test_the_wizard_gets_its_own_component_boundary_separate_from_the_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $content = $this->get(RegisterTenant::getUrl())->assertSuccessful()->getContent();

        $this->assertIsString($content);

        $wireIdCount = substr_count($content, 'wire:id="');

        $this->assertGreaterThanOrEqual(
            3,
            $wireIdCount,
            'Expected at least 3 separate Livewire components in the rendered page (the Filament page, the wizard, and the current step) — found '.$wireIdCount.'. If this dropped to 2, the wizard\'s component boundary collapsed into the page\'s again.'
        );
    }
}
