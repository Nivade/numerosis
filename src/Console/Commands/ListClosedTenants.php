<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * The support view of the recovery window: the common case is a customer
 * changing their mind on day three and mailing support.
 */
#[Description('List closed tenants with the days left before their data is purged')]
#[Signature('tenancy:closed-tenants')]
class ListClosedTenants extends Command
{
    public function handle(): int
    {
        $tenantClass = Numerosis::model(Tenant::class);

        /** @var Collection<int, Tenant> $closed */
        $closed = $tenantClass::query()
            ->whereNotNull('closed_at')
            ->oldest('closed_at')
            ->get();

        if ($closed->isEmpty()) {
            $this->info('No closed tenants.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tenant', 'Owner', 'Closed', 'Purge on', 'Days left'],
            $closed->map(fn (Tenant $tenant): array => [
                $tenant->id,
                $tenant->stripeEmail() ?? '—',
                $tenant->closed_at?->toDateString() ?? '—',
                $tenant->purgeAt()?->toDateString() ?? '—',
                (string) max(0, (int) now()->diffInDays($tenant->purgeAt(), false)),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
