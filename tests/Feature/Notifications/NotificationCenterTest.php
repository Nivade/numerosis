<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Notifications;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Queries\GetUserNotifications;
use Nvade\Numerosis\Livewire\Notifications\Center;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Notifications\Billing\PaymentConfirmed;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The bell and the scope behind it. A person in two workspaces reading from
 * inside one of them must not be shown the other's news — the central row on a
 * tenant screen, in notification form.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_lists_the_users_own_notifications(): void
    {
        [$user, $tenant] = $this->recipient();

        $user->notify(new PaymentConfirmed($tenant));

        $component = Livewire::actingAs($user)->test(Center::class);

        $component->assertViewHas('unread', 1);
        $component->set('open', true)->assertSee('Payment confirmed');
    }

    public function test_opening_the_panel_marks_everything_read(): void
    {
        [$user, $tenant] = $this->recipient();

        $user->notify(new PaymentConfirmed($tenant));

        Livewire::actingAs($user)
            ->test(Center::class)
            ->call('toggle')
            ->assertViewHas('unread', 0);

        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_the_count_does_not_leak_across_users(): void
    {
        [$user, $tenant] = $this->recipient();
        $other = CentralUser::factory()->create();

        $user->notify(new PaymentConfirmed($tenant));

        $this->assertSame(1, GetUserNotifications::unreadCount($user));
        $this->assertSame(0, GetUserNotifications::unreadCount($other));
    }

    /** Read from inside one workspace, the list is that workspace's. */
    public function test_a_tenant_scoped_list_hides_another_tenants_news(): void
    {
        [$user, $alpha] = $this->recipient();
        $beta = Tenant::factory()->create(['name' => 'Beta']);

        $user->notify(new PaymentConfirmed($alpha));
        $user->notify(new PaymentConfirmed($beta));

        $this->assertCount(2, GetUserNotifications::run($user));
        $this->assertCount(1, GetUserNotifications::run($user, (string) $alpha->getTenantKey()));
        $this->assertSame(1, GetUserNotifications::unreadCount($user, (string) $beta->getTenantKey()));
    }

    public function test_read_notifications_are_pruned_and_unread_ones_are_not(): void
    {
        [$user, $tenant] = $this->recipient();

        $user->notify(new PaymentConfirmed($tenant));
        $user->notify(new PaymentConfirmed($tenant));

        // Through the relation, which is the central connection: reaching for
        // `DatabaseNotification::query()` writes on the default one, and the two
        // hold separate transactions over the same rows.
        $rows = $user->notifications()->get();

        $rows->first()?->forceFill(['read_at' => now(), 'created_at' => now()->subYear()])->save();
        $rows->last()?->forceFill(['created_at' => now()->subYear()])->save();

        $command = $this->artisan('numerosis:prune-notifications', ['--days' => 30]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        // `run()` explicitly: a PendingCommand otherwise executes on destruct,
        // which is after every assertion below it.
        $command->assertExitCode(0)->run();

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $user->unreadNotifications()->count());
    }

    /**
     * @return array{0: BaseCentralUser, 1: BaseTenant}
     */
    private function recipient(): array
    {
        Tenant::unsetEventDispatcher();

        return [CentralUser::factory()->create(), Tenant::factory()->create(['name' => 'Acme'])];
    }
}
