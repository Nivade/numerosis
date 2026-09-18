<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use App\Models\Central\CentralUser;
use Livewire\Livewire;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Tests\TestCase;

class NotificationCenterDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([]);

        parent::setUp();
    }

    public function test_the_bell_does_not_render_when_disabled(): void
    {
        $this->actingAsCentralUser(CentralUser::factory()->create());

        Livewire::test('numerosis-layouts::header')
            ->assertDontSee(__('Notifications'));
    }
}
