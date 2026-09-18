<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Privacy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Actions\Auth\RequestPersonalDataExport;
use Nvade\Numerosis\Contracts\Auth\ExportsPersonalData;
use Nvade\Numerosis\Contracts\Tenancy\ExportsTenantData;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Exceptions\Auth\DataExportThrottled;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Notifications\Auth\PersonalDataExportReady;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use ZipArchive;

class PersonalDataExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exports');
        Config::set('numerosis.privacy.disk', 'exports');
    }

    public function test_the_archive_carries_every_tenant_the_subject_belongs_to(): void
    {
        $subject = CentralUser::factory()->create();

        $first = $this->tenantWith($subject);
        $second = $this->tenantWith($subject);

        $path = resolve(ExportsPersonalData::class)->export((string) $subject->global_id);

        $entries = $this->entries($path);

        $this->assertContains('README.md', $entries);
        $this->assertContains('account/profile.json', $entries);
        $this->assertContains('workspaces/'.$first->id.'/tables/users.jsonl', $entries);
        $this->assertContains('workspaces/'.$second->id.'/tables/users.jsonl', $entries);
    }

    /** The assertion that makes the archive safe to hand over. */
    public function test_the_archive_carries_no_other_persons_data(): void
    {
        $subject = CentralUser::factory()->create();
        $bystander = CentralUser::factory()->create(['email' => 'bystander@example.test']);

        $tenant = $this->tenantWith($subject);
        $tenant->users()->attach($bystander->global_id, ['role' => MembershipRole::Member->value]);

        $path = resolve(ExportsPersonalData::class)->export((string) $subject->global_id);

        $contents = $this->allContents($path);

        $this->assertStringContainsString((string) $subject->email, $contents);
        $this->assertStringNotContainsString('bystander@example.test', $contents);
    }

    /**
     * A partial archive answers a subject access request wrongly, so the whole
     * export fails rather than dropping one workspace quietly.
     */
    public function test_an_unreadable_tenant_archive_fails_the_export(): void
    {
        $subject = CentralUser::factory()->create();
        $this->tenantWith($subject);

        app()->instance(ExportsTenantData::class, new class implements ExportsTenantData
        {
            public function export(TenantWithDatabase $tenant, ?string $forGlobalUserId = null, ?string $disk = null): string
            {
                Storage::disk((string) $disk)->put('broken.zip', 'not a zip archive');

                return 'broken.zip';
            }
        });

        $this->expectException(TenantBackupFailed::class);

        resolve(ExportsPersonalData::class)->export((string) $subject->global_id);
    }

    public function test_a_request_is_queued_and_mails_a_single_use_link(): void
    {
        Notification::fake();

        $subject = CentralUser::factory()->create();
        $this->tenantWith($subject);

        RequestPersonalDataExport::run($subject);

        $request = DataExportRequest::query()->firstOrFail();

        $this->assertSame(DataExportStatus::Completed, $request->status);
        $this->assertTrue($request->isDownloadable());

        Notification::assertSentTo($subject, PersonalDataExportReady::class);
    }

    public function test_the_link_is_spent_by_the_first_download(): void
    {
        $subject = CentralUser::factory()->create();
        $this->tenantWith($subject);

        RequestPersonalDataExport::run($subject);

        $request = DataExportRequest::query()->firstOrFail();
        $url = $this->signedDownloadUrl($request);

        $this->actingAsCentralUser($subject);

        $this->get($url)->assertOk();
        $this->get($url)->assertNotFound();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $subject = CentralUser::factory()->create();
        $this->tenantWith($subject);

        RequestPersonalDataExport::run($subject);

        $request = DataExportRequest::query()->firstOrFail();
        $url = $this->signedDownloadUrl($request);

        $request->update(['expires_at' => now()->subMinute()]);

        $this->actingAsCentralUser($subject);

        // Two gates, and this is the row's: the signature was minted for the
        // original expiry and is still valid, so only the stored one refuses.
        $this->get($url)->assertNotFound();
    }

    public function test_somebody_elses_export_is_refused_even_with_a_valid_signature(): void
    {
        $subject = CentralUser::factory()->create();
        $intruder = CentralUser::factory()->create();

        $this->tenantWith($subject);

        RequestPersonalDataExport::run($subject);

        $url = $this->signedDownloadUrl(DataExportRequest::query()->firstOrFail());

        $this->actingAsCentralUser($intruder);

        $this->get($url)->assertForbidden();
    }

    public function test_one_request_per_day(): void
    {
        $subject = CentralUser::factory()->create();
        $this->tenantWith($subject);

        RequestPersonalDataExport::run($subject);

        $this->expectException(DataExportThrottled::class);

        RequestPersonalDataExport::run($subject);
    }

    private function signedDownloadUrl(DataExportRequest $request): string
    {
        return URL::temporarySignedRoute(
            'privacy.exports.download',
            $request->expires_at ?? now()->addHour(),
            ['export' => $request->getRouteKey()],
        );
    }

    private function tenantWith(BaseCentralUser $user): Tenant
    {
        $tenant = $this->createTenantWithDomain('gdpr'.substr(uniqid(), -8), 'Privacy Tenant');

        tenancy()->end();

        $tenant->users()->attach($user->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);

        return $tenant;
    }

    /**
     * @return list<string>
     */
    private function entries(string $path): array
    {
        $zip = $this->open($path);

        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();

        return $entries;
    }

    private function allContents(string $path): string
    {
        $zip = $this->open($path);

        $contents = '';

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $contents .= (string) $zip->getFromIndex($index);
        }

        $zip->close();

        return $contents;
    }

    private function open(string $path): ZipArchive
    {
        $local = (string) tempnam(sys_get_temp_dir(), 'numerosis-export');

        file_put_contents($local, Storage::disk('exports')->get($path));

        $zip = new ZipArchive;
        $zip->open($local);

        return $zip;
    }
}
