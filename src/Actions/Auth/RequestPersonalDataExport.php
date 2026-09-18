<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Exceptions\Auth\DataExportThrottled;
use Nvade\Numerosis\Jobs\GeneratePersonalDataExport;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Numerosis;

/**
 * Records the request and queues the work, which crosses every tenant the
 * subject belongs to. Throttled per subject instead of per IP, since the
 * cost is the databases read, and the person is known.
 *
 * @method static DataExportRequest run(CentralUser $user)
 */
class RequestPersonalDataExport
{
    use AsAction;

    public function handle(CentralUser $user): DataExportRequest
    {
        $this->assertNotThrottled($user);

        /** @var DataExportRequest $request */
        $request = Numerosis::model(DataExportRequest::class)::query()->create([
            'global_user_id' => $user->global_id,
            'status' => DataExportStatus::Pending,
        ]);

        /** @var int $requestId */
        $requestId = $request->id;

        activity()
            ->causedBy($user)
            ->withProperties(['request_ulid' => $request->ulid])
            ->log('Personal data export requested');

        dispatch(new GeneratePersonalDataExport($requestId));

        return $request;
    }

    private function assertNotThrottled(CentralUser $user): void
    {
        $interval = Config::integer('numerosis.privacy.request_interval_hours', 24);

        $recent = Numerosis::model(DataExportRequest::class)::query()
            ->forSubject((string) $user->global_id)
            ->whereNull('tenant_id')
            ->where('created_at', '>=', now()->subHours($interval))
            ->exists();

        if ($recent) {
            throw DataExportThrottled::until($interval);
        }
    }
}
