<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Without the stamp the registry falls back to reading every live payload, and
 * nothing goes red: the device list keeps working and only the query grows.
 */
class SessionOwnerStampTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Repository::class)->set('session.driver', 'database');
        $app->make(Repository::class)->set('session.connection', 'central');
    }

    public function test_a_signed_in_request_stamps_the_row_with_the_global_id(): void
    {
        $user = CentralUser::factory()->create();

        $this->actingAsCentralUser($user)->get('/settings/profile')->assertOk();

        $stamps = DB::connection('central')->table('sessions')->pluck('global_user_id')->all();

        $this->assertSame([$user->global_id], $stamps);
    }

    public function test_a_guest_request_leaves_the_stamp_empty(): void
    {
        $this->get('/')->assertOk();

        $stamps = DB::connection('central')->table('sessions')->pluck('global_user_id')->all();

        $this->assertSame([null], $stamps);
    }
}
