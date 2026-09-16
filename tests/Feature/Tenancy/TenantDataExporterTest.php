<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Services\Tenancy\TenantDataExporter;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;
use ZipArchive;

class TenantDataExporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Config::set('numerosis.tenancy.backup.disk', 'backups');
    }

    public function test_it_writes_a_readable_archive_of_the_tenants_own_rows(): void
    {
        $tenant = $this->tenant();
        $member = CentralUser::factory()->create();

        $tenant->users()->attach($member->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);

        $path = resolve(TenantDataExporter::class)->export($tenant);

        Storage::disk('backups')->assertExists($path);

        $entries = $this->entries($path);

        $this->assertContains('manifest.json', $entries);
        $this->assertContains('tables/users.jsonl', $entries);
        $this->assertContains('central/memberships.jsonl', $entries);
    }

    public function test_a_single_user_export_leaves_out_everybody_else(): void
    {
        $tenant = $this->tenant();

        $subject = CentralUser::factory()->create();
        $bystander = CentralUser::factory()->create();

        foreach ([$subject, $bystander] as $user) {
            $tenant->users()->attach($user->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);
        }

        $path = resolve(TenantDataExporter::class)->export($tenant, $subject->global_id);

        $users = $this->contents($path, 'tables/users.jsonl');

        $this->assertStringContainsString((string) $subject->global_id, $users);
        $this->assertStringNotContainsString((string) $bystander->global_id, $users);
    }

    /**
     * The defect this guards only shows in production, so the ceiling is the
     * assertion: a table read into memory would blow past it.
     */
    public function test_exporting_a_large_table_does_not_load_it_into_memory(): void
    {
        $tenant = $this->tenant();

        // Bulk-inserted rather than created one by one: 2,000 model saves
        // inside tenancy cost minutes, and the subject here is the read.
        $tenant->run(function (): void {
            foreach (array_chunk(range(1, 2_000), 500) as $chunk) {
                DB::connection()->table('users')->insert(array_map(static fn (int $index): array => [
                    'name' => str_repeat('a', 200),
                    'email' => "bulk{$index}@example.test",
                    'global_id' => (string) str()->uuid(),
                    'password' => 'x',
                ], $chunk));
            }
        });

        $before = memory_get_usage(true);

        resolve(TenantDataExporter::class)->export($tenant);

        $this->assertLessThan(
            32 * 1024 * 1024,
            memory_get_usage(true) - $before,
            'The exporter held more than a chunk of the table in memory.'
        );
    }

    private function tenant(): Tenant
    {
        return TestTenant::provisioned(['provisioned_at' => now()]);
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

    private function contents(string $path, string $entry): string
    {
        $zip = $this->open($path);

        $contents = (string) $zip->getFromName($entry);

        $zip->close();

        return $contents;
    }

    private function open(string $path): ZipArchive
    {
        $local = tempnam(sys_get_temp_dir(), 'numerosis-zip');

        file_put_contents($local, Storage::disk('backups')->get($path));

        $zip = new ZipArchive;
        $zip->open((string) $local);

        return $zip;
    }
}
