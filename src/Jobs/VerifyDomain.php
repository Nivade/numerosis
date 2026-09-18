<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvade\Numerosis\Actions\Tenancy\Domains\RecordDomainVerification;
use Nvade\Numerosis\Actions\Tenancy\Domains\VerifyDomainOwnership;
use Nvade\Numerosis\Models\Central\Domain;

/**
 * One DNS check for one domain. Unique per domain, so a customer hammering the
 * re-check button queues one job instead of twenty, and the scheduled sweep
 * cannot pile onto a check already running.
 */
class VerifyDomain implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Domain $domain) {}

    public function uniqueId(): string
    {
        return $this->domain->domain;
    }

    public function handle(): void
    {
        RecordDomainVerification::run($this->domain, VerifyDomainOwnership::run($this->domain));
    }
}
