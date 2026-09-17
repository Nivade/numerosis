<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Exceptions\Auth\DataExportThrottled;
use Nvade\Numerosis\Jobs\GenerateTenantDataExport;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * The whole workspace, unfiltered, for the owner. Shares the request row and
 * the single-use link with a personal export; `tenant_id` is what tells them
 * apart.
 *
 * @method static DataExportRequest run(Tenant $tenant, CentralUser $owner)
 */
class RequestTenantDataExport
{
    use AsAction;

    public function handle(Tenant $tenant, CentralUser $owner): DataExportRequest
    {
        $this->assertNotThrottled($tenant);

        /** @var DataExportRequest $request */
        $request = Numerosis::model(DataExportRequest::class)::query()->create([
            'global_user_id' => $owner->global_id,
            'tenant_id' => $tenant->id,
            'status' => DataExportStatus::Pending,
        ]);

        /** @var int $requestId */
        $requestId = $request->id;

        activity()
            ->causedBy($owner)
            ->performedOn($tenant)
            ->withProperties(['request_ulid' => $request->ulid])
            ->log('Workspace data export requested');

        dispatch(new GenerateTenantDataExport($requestId));

        return $request;
    }

    private function assertNotThrottled(Tenant $tenant): void
    {
        $interval = Config::integer('numerosis.privacy.request_interval_hours', 24);

        $recent = Numerosis::model(DataExportRequest::class)::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->subHours($interval))
            ->exists();

        if ($recent) {
            throw DataExportThrottled::until($interval);
        }
    }
}
