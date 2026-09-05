<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Auth;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Auth\DeleteUserAccount;
use Nvade\Numerosis\Events\Auth\UserAccountDeleted;
use Nvade\Numerosis\Events\Auth\UserAccountDeleting;
use Nvade\Numerosis\Tests\TestCase;

class DeleteUserAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_both_events_in_order_with_the_right_payload(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'member']);

        $order = [];
        $seenDeleting = null;
        $seenDeleted = null;

        Event::listen(UserAccountDeleting::class, function (UserAccountDeleting $event) use (&$order, &$seenDeleting): void {
            $order[] = UserAccountDeleting::class;
            $seenDeleting = $event;
        });
        Event::listen(UserAccountDeleted::class, function (UserAccountDeleted $event) use (&$order, &$seenDeleted): void {
            $order[] = UserAccountDeleted::class;
            $seenDeleted = $event;
        });

        $result = DeleteUserAccount::run($user);

        $this->assertTrue($result);
        $this->assertNull(CentralUser::find($user->id));

        $this->assertSame([UserAccountDeleting::class, UserAccountDeleted::class], $order);
        $this->assertInstanceOf(UserAccountDeleting::class, $seenDeleting);
        $this->assertInstanceOf(UserAccountDeleted::class, $seenDeleted);
        $this->assertSame($user->global_id, $seenDeleting->globalId);
        $this->assertSame(1, $seenDeleting->user->tenants()->count());
        $this->assertSame($user->global_id, $seenDeleted->globalId);
        $this->assertSame($user->email, $seenDeleted->email);
    }

    public function test_an_owner_cannot_delete_their_account_and_dispatches_neither_event(): void
    {
        Event::fake([UserAccountDeleting::class, UserAccountDeleted::class]);

        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        $result = DeleteUserAccount::run($user);

        $this->assertFalse($result);
        $this->assertNotNull(CentralUser::find($user->id));

        Event::assertNotDispatched(UserAccountDeleting::class);
        Event::assertNotDispatched(UserAccountDeleted::class);
    }
}
