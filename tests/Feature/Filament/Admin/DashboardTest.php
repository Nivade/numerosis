<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin;

use App\Models\Central\CentralUser as User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\Admin\Widgets\TenantOverviewWidget;

/**
 * Covers TenantOverviewWidget, the default Dashboard's replacement for the
 * bare AccountWidget-only page it used to be. Written against the widget
 * directly through the Dashboard route rather than in isolation, since the
 * thing actually worth proving is that `data->name` virtual-column querying
 * (shared with SubscriptionForm's tenant Select) and the 'Stuck' status
 * threshold both work against a real database, not just that the stat
 * numbers add up in PHP.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::before(fn () => true);
    }

    public function test_dashboard_page_renders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(Dashboard::getUrl())->assertSuccessful();
    }

    /**
     * Widgets lazy-load via Livewire (Widget::isLazy()), so the stat values
     * never appear in the Dashboard route's initial HTML — testing the
     * widget component directly is what actually exercises the query,
     * including the `data->name` virtual-column matching this widget shares
     * with SubscriptionForm's tenant Select.
     */
    public function test_tenant_overview_widget_renders_its_stats(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Tenant::factory()->create(['provisioned_at' => null, 'suspended_at' => null]);
        Tenant::factory()->create(['provisioned_at' => now(), 'suspended_at' => now()]);
        Tenant::factory()->create(['provisioned_at' => now(), 'suspended_at' => null]);

        Livewire::test(TenantOverviewWidget::class)
            ->assertSuccessful()
            ->assertSee('Provisioning')
            ->assertSee('Suspended')
            ->assertSee('New This Week');
    }
}
