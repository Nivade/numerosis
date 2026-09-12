<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Livewire\Tenant\Registration\RegistrationState;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\CompanyInfo;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\TechnicalSetup;
use Nvade\Numerosis\Tests\Support\HostSecretStep;
use Nvade\Numerosis\Tests\Support\RecordSeatCountStep;
use Nvade\Numerosis\Tests\Support\SeatCountContribution;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The seam the whole pipeline redesign exists for, end to end: a host's wizard
 * step collects data, and a host's provisioning step receives it.
 *
 * Each half was covered on its own -- `RegistrationRefreshTest` for wizard to
 * payload, `ProvisionContributionTest` for payload to row and back -- and the
 * join between them was not, which is the part that used to be impossible:
 * the payload was hand-built field by field from two of core's own steps.
 */
class HostProvisioningExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordSeatCountStep::$seen = [];

        Livewire::component('host-secret-step', HostSecretStep::class);

        Config::set('numerosis.tenancy.registration.steps', [
            CompanyInfo::class,
            TechnicalSetup::class,
            HostSecretStep::class,
        ]);

        Config::set('numerosis.tenancy.provisioning.steps', [
            ...Config::array('numerosis.tenancy.provisioning.steps'),
            RecordSeatCountStep::class,
        ]);
    }

    public function test_a_host_wizard_steps_data_reaches_a_host_provisioning_step(): void
    {
        $user = CentralUser::factory()->create();

        $state = new RegistrationState;
        $state->setAllState([
            'company-info' => ['name' => 'Acme Corp'],
            'technical-setup' => ['domain' => 'seatful'],
            'host-secret-step' => ['seats' => 12],
        ]);

        ProvisionTenant::make()->queue($state->provisionData($user->global_id));

        $this->assertSame(
            ['seatful' => 12],
            RecordSeatCountStep::$seen,
            'A host contribution collected in the wizard did not reach the host step that declared it.',
        );

        /** @var array<class-string, array<string, string>> $records */
        $records = TenantProvision::findOrFail('seatful')->step_records;

        $this->assertSame('done', $records[RecordSeatCountStep::class]['outcome']);
    }

    /**
     * The host step is skipped and recorded, not silently absent, exactly as
     * `LinkTenantSubscription` is when nothing contributed billing.
     */
    public function test_a_host_step_is_skipped_when_the_host_wizard_step_contributed_nothing(): void
    {
        $user = CentralUser::factory()->create();

        $state = new RegistrationState;
        $state->setAllState([
            'company-info' => ['name' => 'Acme Corp'],
            'technical-setup' => ['domain' => 'seatless'],
            'host-secret-step' => [],
        ]);

        ProvisionTenant::make()->queue($state->provisionData($user->global_id));

        $this->assertSame([], RecordSeatCountStep::$seen);

        /** @var array<class-string, array<string, string>> $records */
        $records = TenantProvision::findOrFail('seatless')->step_records;

        $this->assertSame('skipped', $records[RecordSeatCountStep::class]['outcome']);
        $this->assertStringContainsString(
            class_basename(SeatCountContribution::class),
            $records[RecordSeatCountStep::class]['reason'],
        );
    }
}
