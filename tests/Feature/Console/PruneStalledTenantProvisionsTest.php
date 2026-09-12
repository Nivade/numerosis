<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console;

use App\Models\Central\CentralUser;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Tests\TestCase;

class PruneStalledTenantProvisionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_abandoned_reservations_past_the_cutoff(): void
    {
        $this->reservation('abandoned', TenantProvisionStatus::Reserved, now()->subHours(5));
        $this->reservation('recent', TenantProvisionStatus::Reserved, now()->subMinutes(5));

        $this->pruneStalledProvisions()->assertSuccessful();

        $this->assertNull(TenantProvision::find('abandoned'));
        $this->assertNotNull(TenantProvision::find('recent'));
    }

    public function test_it_logs_stalled_provisions_instead_of_deleting_them(): void
    {
        $spy = Log::spy();

        $this->reservation('stalled', TenantProvisionStatus::Provisioning, now()->subHours(5));

        $this->pruneStalledProvisions()->assertSuccessful();

        // The customer already paid, so the row is kept for a human to look at.
        $this->assertNotNull(TenantProvision::find('stalled'));

        $spy->shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Tenant provisioning stalled'
                && $context['domain'] === 'stalled');
    }

    public function test_it_leaves_already_failed_rows_alone(): void
    {
        $this->reservation('brokendomain', TenantProvisionStatus::Failed, now()->subHours(5));

        $this->pruneStalledProvisions()->assertSuccessful();

        $this->assertNotNull(TenantProvision::find('brokendomain'));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->reservation('abandoned', TenantProvisionStatus::Reserved, now()->subHours(5));
        $this->reservation('stalled', TenantProvisionStatus::Provisioning, now()->subHours(5));

        $this->pruneStalledProvisions(['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(TenantProvision::find('abandoned'));
        $this->assertNotNull(TenantProvision::find('stalled'));
    }

    /**
     * `artisan()` is typed `PendingCommand|int` — it returns the int only once
     * expectations have been run. Narrowing here keeps every test a single
     * chained call without a baseline entry.
     *
     * @param  array<string, mixed>  $options
     */
    private function pruneStalledProvisions(array $options = []): PendingCommand
    {
        $command = $this->artisan('tenancy:prune-stalled-provisions', $options);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function reservation(string $domain, TenantProvisionStatus $status, Carbon $createdAt): void
    {
        $user = CentralUser::factory()->create();

        $pending = TenantProvision::create([
            'slug' => $domain,
            'name' => 'Test Co',
            'global_id' => $user->global_id,
            'status' => $status,
        ]);

        $pending->forceFill(['created_at' => $createdAt])->save();
    }
}
