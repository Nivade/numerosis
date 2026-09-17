<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Privacy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Notifications\Auth\PersonalDataExportReady;
use Nvade\Numerosis\Tests\TestCase;

/** The owner's "we are leaving, give us everything" path. */
class TenantDataExportRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exports');
        Config::set('numerosis.privacy.disk', 'exports');
    }

    public function test_an_owner_exports_the_whole_workspace(): void
    {
        Notification::fake();

        [$tenant, $domain, $owner] = $this->tenantWithOwner();

        $this->actingAsTenantUser($owner);

        $this->post('http://'.$domain.'/team/export')->assertRedirect();

        $request = DataExportRequest::query()->firstOrFail();

        $this->assertSame($tenant->id, $request->tenant_id);
        $this->assertSame(DataExportStatus::Completed, $request->status);

        Storage::disk('exports')->assertExists((string) $request->path);

        Notification::assertSentTo(
            CentralUser::query()->where('global_id', $owner->global_id)->firstOrFail(),
            PersonalDataExportReady::class
        );
    }

    public function test_a_member_cannot_export_the_workspace(): void
    {
        [$tenant, $domain] = $this->tenantWithOwner();

        $member = $this->memberOf($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($member);

        $this->post('http://'.$domain.'/team/export')->assertForbidden();

        $this->assertSame(0, DataExportRequest::query()->count());
    }

    /**
     * @return array{0: Tenant, 1: string, 2: TenantUser}
     */
    private function tenantWithOwner(): array
    {
        $id = 'exp'.substr(uniqid(), -8);
        $tenant = $this->createTenantWithDomain($id, 'Export Tenant');

        return [$tenant, $this->tenantDomain($id), $this->memberOf($tenant, MembershipRole::Owner)];
    }

    private function memberOf(Tenant $tenant, MembershipRole $role): TenantUser
    {
        tenancy()->end();

        $central = CentralUser::factory()->create();

        $tenant->users()->attach($central->global_id, ['role' => $role->value, 'joined_at' => now()]);

        /** @var TenantUser $member */
        $member = $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        return $member;
    }
}
