<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Auth\ExportsPersonalData;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
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
            $this->fail($request, 'The account was removed before the export ran.');

            return;
        }

        $disk = Config::string('numerosis.privacy.disk', Config::string('numerosis.tenancy.backup.disk', 'local'));

        try {
            $path = $exporter->export((string) $user->global_id, $disk);
        } catch (Throwable $failure) {
            $this->fail($request, $failure->getMessage());

            throw $failure;
        }

        $request->update([
            'status' => DataExportStatus::Completed,
            'disk' => $disk,
            'path' => $path,
            'completed_at' => now(),
            'expires_at' => now()->addMinutes(Config::integer('numerosis.privacy.link_expiry_minutes', 60)),
        ]);

        activity()
            ->causedBy($user)
            ->withProperties(['request_ulid' => $request->ulid])
            ->log('Personal data export produced');

        $user->notify(new PersonalDataExportReady($request));
    }

    private function fail(DataExportRequest $request, string $reason): void
    {
        $request->update([
            'status' => DataExportStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
