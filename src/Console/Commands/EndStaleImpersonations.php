<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Actions\Admin\EndImpersonation;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Numerosis;

/**
 * Closes impersonation rows whose session ran past
 * `numerosis.tenancy.impersonation.session_minutes`.
 *
 * `GuardImpersonation` ends one on the next request, so this exists for the
 * sessions nobody returns to: an abandoned row would otherwise read as an open
 * one for good, which is the state a compliance report is about.
 */
#[Description('Close impersonation sessions left open past the configured cap')]
#[Signature('impersonation:end-stale {--dry-run : Report what would be closed without closing it}')]
class EndStaleImpersonations extends Command
{
    public function handle(): void
    {
        $stale = Numerosis::model(ImpersonationSession::class)::query()->stale()->get();

        if ($this->option('dry-run')) {
            $this->line("Would close {$stale->count()} impersonation sessions");

            return;
        }

        foreach ($stale as $session) {
            EndImpersonation::run($session, ImpersonationEndReason::Expired);
        }

        $this->info("Closed {$stale->count()} impersonation sessions.");
    }
}
