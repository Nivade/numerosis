<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Auth;

use Nvade\Numerosis\Actions\Auth\ConnectSocialAccount;
use Nvade\Numerosis\Events\Auth\SocialAccountConnected;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountConnected;
use Nvade\Numerosis\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Nvade\Numerosis\Tests\TestCase;

class LogSocialAccountConnectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_the_connected_provider(): void
    {
        $user = CentralUser::factory()->create();
        $socialLogin = ConnectSocialAccount::run($user, 'github', 'github-123');

        (new LogSocialAccountConnected)->handle(new SocialAccountConnected($user, $socialLogin));

        $activity = Activity::query()->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('Connected github social account', $activity->description);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(['provider' => 'github'], $activity->properties?->toArray());
    }
}
