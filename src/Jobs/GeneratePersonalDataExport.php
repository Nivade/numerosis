<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvade\Numerosis\Contracts\Auth\ExportsPersonalData;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Jobs\Concerns\WritesDataExportOutcome;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Notifications\Auth\PersonalDataExportReady;
use Nvade\Numerosis\Numerosis;
use Throwable;

/**
 * Queued because it reads every tenant database the subject belongs to. The
 * request row carries the outcome either way: a failure a person is waiting on
 * has to be visible somewhere they can be told about.
 */
final class GeneratePersonalDataExport implements ShouldQueue
{
    use Queueable;
    use WritesDataExportOutcome;

    public int $tries = 1;

    public function __construct(public readonly int $requestId) {}

    public function handle(ExportsPersonalData $exporter): void
    {
        $request = Numerosis::model(DataExportRequest::class)::query()->find($this->requestId);

        if (! $request instanceof DataExportRequest || $request->status !== DataExportStatus::Pending) {
            return;
        }

        $user = Numerosis::model(CentralUser::class)::query()
            ->where('global_id', $request->global_user_id)
            ->first();

        if (! $user instanceof CentralUser) {
            $this->markFailed($request, 'The account was removed before the export ran.');

            return;
        }

        $disk = $this->exportDisk();

        try {
            $path = $exporter->export((string) $user->global_id, $disk);
        } catch (Throwable $failure) {
            $this->markFailed($request, $failure->getMessage());

            throw $failure;
        }

        $this->markCompleted($request, $disk, $path);

        activity()
            ->causedBy($user)
            ->withProperties(['request_ulid' => $request->ulid])
            ->log('Personal data export produced');

        $user->notify(new PersonalDataExportReady($request));
    }
}
