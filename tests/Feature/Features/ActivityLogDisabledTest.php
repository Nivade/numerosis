<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use Filament\Facades\Filament;
use Nvade\Numerosis\Features\Ui\TenantPanelFeature;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\ActivityResource;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The plugin's absence is a boot-time assertion — ->plugins([...]) is
 * evaluated while the panel provider boots, so the override must be forced
 * before parent::setUp(). Unlike Phase 2's Marketplace page, plugins are not
 * auto-discovered, so gating the array entry actually works here.
 */
class ActivityLogDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        // TenantPanelFeature must stay on — forceForTesting([]) also disables
        // it, so Filament::getPanel('tenantAdmin') returns null and the first
        // assertion below dies on "Call to a member function hasPlugin() on
        // null" before ever checking the plugin itself.
        Features::forceForTesting([TenantPanelFeature::class]);

        parent::setUp();
    }

    public function test_the_plugin_is_not_registered_when_disabled(): void
    {
        $this->assertFalse(Filament::getPanel('tenantAdmin')->hasPlugin(ActivityLogPlugin::make()->getId()));
    }

    public function test_the_resource_is_inaccessible_when_disabled(): void
    {
        $this->assertFalse(ActivityResource::canAccess());
    }
}
