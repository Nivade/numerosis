<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Lorisleiva\Actions\Decorators\UniqueJobDecorator;
use Nvade\Numerosis\Actions\Billing\Checkout\StartLocalCheckout;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Tests\TestCase;

class StartLocalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_pending_provision_and_queues_the_real_job(): void
    {
        Bus::fake();

        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $intent = StartLocalCheckout::run(TenantRegistrationData::from([
            'company_name' => 'Dev Co',
            'domain' => 'devtenant',
            'billing_cycle' => BillingCycle::Monthly,
            'global_id' => $user->global_id,
        ]));

        $this->assertInstanceOf(RedirectCheckout::class, $intent);
        $this->assertSame(route('tenants.mine'), $intent->url);

        $pending = PendingTenantProvision::find('devtenant');

        $this->assertNotNull($pending);
        $this->assertSame(TenantProvisionStatus::Provisioning, $pending->status);
        $this->assertSame('Dev Co', $pending->company_name);

        // The dev shortcut must go through the same queued action as the paid
        // flow, otherwise it exercises a path production never takes.
        Bus::assertDispatched(fn (UniqueJobDecorator $job) => $job->decorates(ProvisionTenant::class));
    }

    /**
     * The route provisions a paid resource for free, so the guard has to sit on
     * the route registration itself rather than on the link that reaches it.
     * The test environment is not `local`, so the route must be absent here.
     */
    public function test_the_route_is_not_registered_outside_local(): void
    {
        $this->assertFalse(app()->isLocal());

        $registered = collect(Route::getRoutes()->getRoutes())
            ->contains(fn ($route) => $route->getName() === 'checkout.subscription.dev');

        $this->assertFalse($registered, 'The dev checkout route must only exist in the local environment.');
    }
}
