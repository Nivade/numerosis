<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration\Steps;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Nvade\Numerosis\Livewire\Tenant\Registration\Registration;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\CompanyInfo;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\TechnicalSetup;
use Nvade\Numerosis\Support\State\RegistrationState;
use Nvade\Numerosis\Tests\TestCase;

class TechnicalSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The whole point of reserving here rather than at Plan/register() is to
     * surface a taken domain before the user has picked a plan or entered
     * payment details, not after.
     */
    public function test_it_reserves_the_domain_immediately_on_continue(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $this->technicalSetupStep(['company-info' => ['company_name' => 'Acme']])
            ->set('domain', 'acme-reserve-test')
            ->call('continue')
            ->assertDispatchedTo(Registration::class, 'nextStep');

        $this->assertDatabaseHas(PendingTenantProvision::class, [
            'domain' => 'acme-reserve-test',
            'status' => 'reserved',
        ]);
    }

    public function test_it_rejects_a_domain_someone_else_already_reserved(): void
    {
        $owner = CentralUser::factory()->create();
        PendingTenantProvision::factory()->create([
            'domain' => 'taken-domain',
            'global_id' => $owner->global_id,
        ]);

        $this->actingAs(CentralUser::factory()->create());

        $this->technicalSetupStep(['company-info' => ['company_name' => 'Acme']])
            ->set('domain', 'taken-domain')
            ->call('continue')
            ->assertHasErrors('domain')
            ->assertNotDispatched('nextStep');
    }

    /**
     * Re-submitting the same step (e.g. Back then Continue again) must not
     * self-block on the reservation this same action already created.
     */
    public function test_it_does_not_self_block_on_its_own_reservation(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        PendingTenantProvision::factory()->create([
            'domain' => 'own-domain-retry',
            'global_id' => $user->global_id,
        ]);

        $this->technicalSetupStep(['company-info' => ['company_name' => 'Acme']])
            ->set('domain', 'own-domain-retry')
            ->call('continue')
            ->assertDispatchedTo(Registration::class, 'nextStep')
            ->assertHasNoErrors();
    }

    /**
     * wire:model.blur.live only syncs the value; errors used to appear only
     * after clicking Continue. updatedDomain() runs the same rules() as soon
     * as the field updates, without needing to submit.
     */
    public function test_it_validates_as_the_domain_field_updates_without_continuing(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $this->technicalSetupStep(['company-info' => ['company_name' => 'Acme']])
            ->set('domain', 'admin')
            ->assertHasErrors('domain');
    }

    public function test_it_rejects_a_reserved_word_domain(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $this->technicalSetupStep(['company-info' => ['company_name' => 'Acme']])
            ->set('domain', 'admin')
            ->call('continue')
            ->assertHasErrors('domain')
            ->assertNotDispatched('nextStep')
            // The "your workspace URL will be" preview must not endorse a
            // domain the same request just rejected — it used to render
            // unconditionally off $domain alone, with no validity check.
            ->assertDontSee('https://admin.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $stepsState
     * @return Testable<TechnicalSetup>
     */
    private function technicalSetupStep(array $stepsState): Testable
    {
        return Livewire::test(TechnicalSetup::class, [
            'wizardClassName' => Registration::class,
            'stateClassName' => RegistrationState::class,
            'allStepNames' => [
                resolve('livewire.finder')->normalizeName(CompanyInfo::class),
                resolve('livewire.finder')->normalizeName(TechnicalSetup::class),
            ],
            'allStepsState' => $stepsState,
        ]);
    }
}
