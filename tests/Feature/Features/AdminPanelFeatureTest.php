<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class AdminPanelFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_panel_is_registered_when_enabled(): void
    {
        $this->assertArrayHasKey('admin', Filament::getPanels());
    }
}
