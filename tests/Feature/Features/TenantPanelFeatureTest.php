<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class TenantPanelFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tenant_panel_is_registered_when_enabled(): void
    {
        $this->assertArrayHasKey('tenantAdmin', Filament::getPanels());
    }
}
