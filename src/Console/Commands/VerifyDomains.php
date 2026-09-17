<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Tenancy\Domains\RecordDomainVerification;
use Nvade\Numerosis\Actions\Tenancy\Domains\VerifyDomainOwnership;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Jobs\VerifyDomain;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Numerosis;

/**
 * Re-checks claimed domains, and the serving ones too: a domain whose DNS was
 * pulled has to stop being served, and nothing else would notice.
 */
#[Description('Re-check DNS for claimed and serving custom domains')]
#[Signature('numerosis:verify-domains
                            {--domain=* : Hostnames to check, ignoring the recheck interval}
                            {--limit=100 : How many overdue domains to take}
                            {--sync : Check in this process instead of queueing}')]
class VerifyDomains extends Command
{
    public function handle(): int
    {
        $checked = 0;

        foreach ($this->domains() as $domain) {
            if ($this->option('sync') === true) {
                RecordDomainVerification::run($domain, VerifyDomainOwnership::run($domain));
            } else {
                dispatch(new VerifyDomain($domain));
            }

            $checked++;
        }

        $this->components->info($this->option('sync') === true
            ? "Checked {$checked} domain(s)."
            : "Queued {$checked} domain check(s).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Domain>
     */
    private function domains(): Collection
    {
        /** @var list<string> $hostnames */
        $hostnames = array_values(array_filter((array) $this->option('domain'), is_string(...)));

        $query = Numerosis::model(Domain::class)::query()->dueForCheck();

        if ($hostnames !== []) {
            /** @var Collection<int, Domain> $named */
            $named = Numerosis::model(Domain::class)::query()
                ->whereIn('domain', $hostnames)
                ->where('status', '!=', DomainStatus::Revoked->value)
                ->get();

            return $named;
        }

        // The interval is per domain, not per run: a fleet of thousands is swept
        // in batches without checking any one of them more often than this.
        $minutes = Config::integer('numerosis.tenancy.custom_domains.recheck_minutes', 60);

        /** @var Collection<int, Domain> $due */
        $due = $query
            ->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subMinutes($minutes)))
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        return $due;
    }
}
