<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Events\Auth\SocialAccountDisconnected;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountDisconnected;
use Nvade\Numerosis\Tests\TestCase;
use Spatie\Activitylog\Models\Activity;

class LogSocialAccountDisconnectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_the_disconnected_provider(): void
    {
        $user = CentralUser::factory()->create();

        (new LogSocialAccountDisconnected)->handle(new SocialAccountDisconnected($user, 'github'));

        $activity = Activity::query()->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('Disconnected github social account', $activity->description);
        $this->assertSame($user->id, $activity->causer_id);
    }
}
