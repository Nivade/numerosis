<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `NumerosisServiceProvider::packageRegistered()` supplies defaults for
 * three Livewire/filesystem config keys a host previously had to wire by
 * hand (see .claude/plans/cleanup-package-extraction.md, item H) — but only
 * when the host hasn't already set something. They live in the *register*
 * phase, not `packageBooted()`, because `LivewireServiceProvider::boot()`
 * reads `component_namespaces` eagerly to register a Blade view-finder
 * hint; setting it in `packageBooted()` was too late and produced "No hint
 * path defined for [layouts]." the first time thin-app rendered a real page
 * (`packageBooted()` alone was never enough — this was only caught by
 * hitting a real route, not by any test, which is exactly the risk this
 * test now covers going forward).
 *
 * `TestCase::getEnvironmentSetUp()` always sets these explicitly (it stands
 * in for a real host), so those defaults are never exercised by the rest of
 * the suite. This resets the keys to "unset" and re-runs
 * packageRegistered() directly to prove the fallback actually fires, rather
 * than trusting it by inspection.
 */
class NumerosisServiceProviderDefaultsTest extends TestCase
{
    public function test_it_defaults_the_livewire_upload_disk_when_unset(): void
    {
        Config::set('livewire.temporary_file_upload.disk', null);

        $this->rebootPackage();

        $this->assertSame('livewire', Config::get('livewire.temporary_file_upload.disk'));
    }

    public function test_it_does_not_override_a_hosts_explicit_upload_disk(): void
    {
        Config::set('livewire.temporary_file_upload.disk', 's3');

        $this->rebootPackage();

        $this->assertSame('s3', Config::get('livewire.temporary_file_upload.disk'));
    }

    public function test_it_defaults_the_livewire_disk_when_unset(): void
    {
        Config::set('filesystems.disks.livewire', null);

        $this->rebootPackage();

        $this->assertSame(storage_path('app/private'), Config::get('filesystems.disks.livewire.root'));
    }

    public function test_it_defaults_component_namespaces_when_unset_or_still_livewires_stock_default(): void
    {
        Config::set('livewire.component_namespaces.layouts', null);
        Config::set('livewire.component_namespaces.pages', resource_path('views/pages'));

        $this->rebootPackage();

        $layouts = Config::string('livewire.component_namespaces.layouts');
        $pages = Config::string('livewire.component_namespaces.pages');

        $this->assertSame(realpath(__DIR__.'/../../resources/views/layouts'), realpath($layouts));
        $this->assertSame(realpath(__DIR__.'/../../resources/views/pages'), realpath($pages));
    }

    public function test_it_does_not_override_a_hosts_custom_component_namespace(): void
    {
        Config::set('livewire.component_namespaces.layouts', '/tmp/custom-layouts');

        $this->rebootPackage();

        $this->assertSame('/tmp/custom-layouts', Config::get('livewire.component_namespaces.layouts'));
    }

    private function rebootPackage(): void
    {
        $provider = $this->app?->getProvider(NumerosisServiceProvider::class);

        $this->assertInstanceOf(NumerosisServiceProvider::class, $provider);

        $provider->packageRegistered();
    }
}
