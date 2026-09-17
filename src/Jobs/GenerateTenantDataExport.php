<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvade\Numerosis\Contracts\Tenancy\ExportsTenantData;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Jobs\Concerns\WritesDataExportOutcome;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Notifications\Auth\PersonalDataExportReady;
use Nvade\Numerosis\Numerosis;
use Throwable;

/** The whole-workspace half of {@see GeneratePersonalDataExport}. */
final class GenerateTenantDataExport implements ShouldQueue
{
    use Queueable;
    use WritesDataExportOutcome;

    public int $tries = 1;

    public function __construct(public readonly int $requestId) {}

    public function handle(ExportsTenantData $exporter): void
    {
        $request = Numerosis::model(DataExportRequest::class)::query()->find($this->requestId);

        if (! $request instanceof DataExportRequest || $request->status !== DataExportStatus::Pending) {
            return;
        }

        $tenant = Numerosis::model(Tenant::class)::query()->find($request->tenant_id);
        $owner = Numerosis::model(CentralUser::class)::query()->where('global_id', $request->global_user_id)->first();

        if (! $tenant instanceof Tenant || ! $owner instanceof CentralUser) {
            $this->markFailed($request, 'The workspace or the person who asked no longer exists.');

            return;
        }

        $disk = $this->exportDisk();

        try {
            $path = $exporter->export($tenant, null, $disk);
        } catch (Throwable $failure) {
            $this->markFailed($request, $failure->getMessage());

            throw $failure;
        }

        $this->markCompleted($request, $disk, $path);

        activity()
            ->causedBy($owner)
            ->performedOn($tenant)
            ->withProperties(['request_ulid' => $request->ulid])
            ->log('Workspace data export produced');

        $owner->notify(new PersonalDataExportReady($request));
    }
}
