<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Lorisleiva\Actions\Decorators\UniqueJobDecorator;
use Nvade\Numerosis\Actions\Billing\Checkout\StartLocalCheckout;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Tests\TestCase;

class StartLocalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read by `getEnvironmentSetUp()`, which runs before the route file, so
     * the environment has to be decided before `refreshApplication()` rather
     * than inside the test body.
     */
    private static bool $local = false;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if (self::$local) {
            $app['env'] = 'local';
        }
    }

    public function test_it_records_the_pending_provision_and_queues_the_real_job(): void
    {
        Bus::fake();

        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $intent = StartLocalCheckout::run(TenantProvisionData::from([
            'name' => 'Dev Co',
            'slug' => 'devtenant',
            'billing_cycle' => BillingCycle::Monthly,
            'global_id' => $user->global_id,
        ]));

        $this->assertInstanceOf(RedirectCheckout::class, $intent);
        $this->assertSame(route('tenants.mine'), $intent->url);

        $pending = TenantProvision::find('devtenant');

        $this->assertNotNull($pending);
        $this->assertSame(TenantProvisionStatus::Provisioning, $pending->status);
        $this->assertSame('Dev Co', $pending->name);

        // The dev shortcut must go through the same queued action as the paid
        // flow, otherwise it exercises a path production never takes.
        Bus::assertDispatched(fn (UniqueJobDecorator $job) => $job->decorates(ProvisionTenant::class));
    }

    /**
     * The wizard's "Skip Stripe" link is the only way this route is reached,
     * and it is registered only under `local`, so nothing exercised the link's
     * query keys against the request's rules. They drifted apart the moment
     * the request started validating `slug` instead of `domain`.
     */
    public function test_the_dev_link_reaches_the_route_with_the_keys_it_validates(): void
    {
        self::$local = true;

        try {
            $this->refreshApplication();

            Bus::fake();

            $user = CentralUser::factory()->create();
            $this->actingAs($user);

            $link = (string) file_get_contents(
                dirname(__DIR__, 5).'/resources/views/livewire/tenant/registration/wizard/steps/plan.blade.php',
            );

            $this->assertStringContainsString("'slug' => \$this->state()->get('domain')", $link);

            $this->get(route('checkout.subscription.dev', [
                'name' => 'Dev Co',
                'slug' => 'devlink',
                'billing_cycle' => 'monthly',
                'global_id' => $user->global_id,
            ]))->assertRedirect(route('tenants.mine'));

            $this->assertNotNull(TenantProvision::find('devlink'));
        } finally {
            self::$local = false;
            $this->refreshApplication();
        }
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
