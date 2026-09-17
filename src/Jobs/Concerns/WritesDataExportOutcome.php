<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs\Concerns;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Models\Central\DataExportRequest;

/**
 * The request row is what the person waiting is told from, so both halves of
 * the export write their outcome the same way.
 */
trait WritesDataExportOutcome
{
    protected function exportDisk(): string
    {
        return Config::string('numerosis.privacy.disk', Config::string('numerosis.tenancy.backup.disk', 'local'));
    }

    protected function markCompleted(DataExportRequest $request, string $disk, string $path): void
    {
        $request->update([
            'status' => DataExportStatus::Completed,
            'disk' => $disk,
            'path' => $path,
            'completed_at' => now(),
            'expires_at' => now()->addMinutes(Config::integer('numerosis.privacy.link_expiry_minutes', 60)),
        ]);
    }

    protected function markFailed(DataExportRequest $request, string $reason): void
    {
        $request->update([
            'status' => DataExportStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
