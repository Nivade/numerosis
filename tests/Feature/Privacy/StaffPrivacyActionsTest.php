<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Privacy;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Enums\Auth\SystemRole;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Requests that arrive by mail rather than through the product. Staff start
 * the work; the download link still goes to the subject.
 */
class StaffPrivacyActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([StaffPanelFeature::class]);

        parent::setUp();

        Storage::fake('exports');
        Config::set('numerosis.privacy.disk', 'exports');

        // Before any user exists: `CentralUserObserver` promotes the first
        // central user, and a role lookup that misses is cached, so seeding
        // afterwards leaves `assignRole('admin')` throwing.
        (new RoleAndPermissionSeeder)->run();
    }

    public function test_staff_can_start_an_export_for_somebody_else(): void
    {
        $subject = CentralUser::factory()->create();

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.users')
            ->call('exportData', $subject->getKey());

        $this->assertSame(
            1,
            DataExportRequest::query()->where('global_user_id', $subject->global_id)->count()
        );
    }

    public function test_staff_erasure_refuses_an_owner(): void
    {
        $owner = CentralUser::factory()->create();
        $tenant = $this->createTenantWithDomain('staffgdpr'.substr(uniqid(), -6), 'Owned Tenant');

        tenancy()->end();

        $tenant->users()->attach($owner->global_id, ['role' => MembershipRole::Owner->value, 'joined_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.users')
            ->call('eraseData', $owner->getKey());

        $this->assertNull(CentralUser::findOrFail($owner->id)->anonymized_at);
    }

    public function test_staff_erasure_anonymizes_a_member(): void
    {
        $member = CentralUser::factory()->create();

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.users')
            ->call('eraseData', $member->getKey());

        $this->assertNotNull(CentralUser::withTrashed()->findOrFail($member->id)->anonymized_at);
    }

    private function admin(): BaseCentralUser
    {
        // The decoy covers `CentralUserObserver`'s promotion of the first
        // central user, which would otherwise make every subject an admin.
        CentralUser::factory()->create();

        $admin = CentralUser::factory()->create();
        $admin->assignRole(SystemRole::Admin->value);

        return $this->withConfirmedTwoFactor($admin);
    }
}
