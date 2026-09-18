<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvade\Numerosis\Actions\Tenancy\BackupTenant;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenantBackup;
use Nvade\Numerosis\Actions\Tenancy\RewriteClonedTenantReferences;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The artefacts go to a faked disk, but the dump and the restore are real
 * statements against a real tenant database — the round trip is the only thing
 * that proves either half.
 */
class TenantBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Config::set('numerosis.tenancy.backup.disk', 'backups');
    }

    public function test_a_tenant_round_trips_through_an_artefact(): void
    {
        $tenant = $this->tenant();

        $this->seedUsers($tenant, ['first@example.test', 'second@example.test']);

        $path = BackupTenant::run($tenant);

        Storage::disk('backups')->assertExists($path);

        $this->truncateUsers($tenant);
        $this->assertSame(0, $this->userCount($tenant));

        RestoreTenantBackup::run($tenant, $path, force: true);

        $this->assertSame(2, $this->userCount($tenant));
        $this->assertSame(
            ['first@example.test', 'second@example.test'],
            $this->emails($tenant)
        );
    }

    public function test_chunk_changes_the_restore_batch_size_and_the_artefact_still_round_trips(): void
    {
        Config::set('numerosis.tenancy.backup.encrypt', false);

        $tenant = $this->tenant();

        $this->seedUsers($tenant, ['first@example.test', 'second@example.test']);

        $path = BackupTenant::run($tenant, null, 1);

        $header = json_decode(strtok((string) Storage::disk('backups')->get($path), "\n") ?: '', true);

        $this->assertIsArray($header);
        $this->assertSame(1, $header['chunk']);

        $this->truncateUsers($tenant);

        RestoreTenantBackup::run($tenant, $path, force: true);

        $this->assertSame(2, $this->userCount($tenant));
    }

    public function test_the_backup_command_wires_chunk_into_the_artefact(): void
    {
        Config::set('numerosis.tenancy.backup.encrypt', false);

        $tenant = $this->tenant();

        $this->seedUsers($tenant, ['only@example.test']);

        $exitCode = Artisan::call('tenancy:backup', ['tenant' => $tenant->id, '--chunk' => '1']);

        $this->assertSame(0, $exitCode);

        $files = Storage::disk('backups')->allFiles("tenant-backups/{$tenant->id}");
        $this->assertNotEmpty($files);

        $header = json_decode(strtok((string) Storage::disk('backups')->get($files[0]), "\n") ?: '', true);

        $this->assertIsArray($header);
        $this->assertSame(1, $header['chunk']);
    }

    /** Alphabetically `role_has_permissions` comes first, and restoring it first violates its own foreign key. */
    public function test_the_artefact_lists_a_parent_table_before_its_child(): void
    {
        Config::set('numerosis.tenancy.backup.encrypt', false);

        $tenant = $this->tenant();

        $this->seedUsers($tenant, ['ordered@example.test']);

        $path = BackupTenant::run($tenant);

        $header = json_decode(strtok((string) Storage::disk('backups')->get($path), "\n") ?: '', true);

        $this->assertIsArray($header);
        $this->assertIsArray($header['tables']);

        $tables = array_values($header['tables']);

        $this->assertLessThan(
            array_search('role_has_permissions', $tables, true),
            array_search('roles', $tables, true),
        );
    }

    public function test_an_artefact_is_encrypted_at_rest(): void
    {
        $tenant = $this->tenant();

        $this->seedUsers($tenant, ['secret-address@example.test']);

        $path = BackupTenant::run($tenant);

        $this->assertStringEndsWith('.enc', $path);
        $this->assertStringNotContainsString(
            'secret-address@example.test',
            Storage::disk('backups')->get($path) ?? ''
        );
    }

    public function test_encryption_can_be_turned_off(): void
    {
        Config::set('numerosis.tenancy.backup.encrypt', false);

        $tenant = $this->tenant();

        $this->seedUsers($tenant, ['plain@example.test']);

        $path = BackupTenant::run($tenant);

        $this->assertStringEndsWith('.dump', $path);
        $this->assertStringContainsString('plain@example.test', Storage::disk('backups')->get($path) ?? '');
    }

    public function test_restoring_over_a_tenant_that_holds_rows_needs_force(): void
    {
        $tenant = $this->tenant();

        $this->seedUsers($tenant, ['occupant@example.test']);

        $path = BackupTenant::run($tenant);

        $this->expectException(TenantBackupFailed::class);

        RestoreTenantBackup::run($tenant, $path);
    }

    public function test_a_missing_artefact_is_named_rather_than_thrown_from_the_filesystem(): void
    {
        $tenant = $this->tenant();

        $this->expectException(TenantBackupFailed::class);
        $this->expectExceptionMessage('No backup artefact at');

        RestoreTenantBackup::run($tenant, 'tenant-backups/nothing-here.dump.enc', force: true);
    }

    public function test_a_clone_keeps_no_reference_to_the_tenant_it_came_from(): void
    {
        $source = $this->tenant();
        $target = $this->tenant();

        $this->seedUsers($source, ['cloned@example.test']);

        $source->run(function () use ($source): void {
            TenantUser::query()->where('email', 'cloned@example.test')
                ->update(['name' => 'Belongs to '.$source->id]);
        });

        $path = BackupTenant::run($source);

        RestoreTenantBackup::run($target, $path, force: true);
        RewriteClonedTenantReferences::run($target, $source->id);

        /** @var list<string> $names */
        $names = $target->run(fn (): array => TenantUser::query()->pluck('name')->all());

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertStringNotContainsString($source->id, $name);
            $this->assertStringContainsString($target->id, $name);
        }
    }

    private function tenant(): Tenant
    {
        return TestTenant::provisioned(['provisioned_at' => now()]);
    }

    /**
     * @param  list<string>  $emails
     */
    private function seedUsers(Tenant $tenant, array $emails): void
    {
        $this->truncateUsers($tenant);

        $tenant->run(function () use ($emails): void {
            foreach ($emails as $email) {
                TenantUser::query()->create([
                    'name' => 'Backup Subject',
                    'email' => $email,
                    'global_id' => (string) str()->uuid(),
                    'password' => 'x',
                ]);
            }
        });
    }

    private function truncateUsers(Tenant $tenant): void
    {
        $tenant->run(fn () => DB::connection()->table('users')->delete());
    }

    private function userCount(Tenant $tenant): int
    {
        /** @var int $count */
        $count = $tenant->run(fn (): int => TenantUser::query()->count());

        return $count;
    }

    /**
     * @return list<string>
     */
    private function emails(Tenant $tenant): array
    {
        /** @var list<string> $emails */
        $emails = $tenant->run(fn (): array => TenantUser::query()->orderBy('email')->pluck('email')->all());

        return $emails;
    }
}
