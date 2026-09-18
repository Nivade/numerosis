<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;

/**
 * Support answering a request that arrived by mail instead of through the
 * product. The link still goes to the subject's own address: staff start the
 * export, they do not receive it.
 *
 * @method static DataExportRequest run(CentralUser $staff, CentralUser $subject)
 */
class ExportUserDataForStaff
{
    use AsAction;

    public const string PERMISSION = 'exportData';

    public function handle(CentralUser $staff, CentralUser $subject): DataExportRequest
    {
        $request = RequestPersonalDataExport::run($subject);

        activity()
            ->causedBy($staff)
            ->withProperties([
                'subject_global_id' => $subject->global_id,
                'request_ulid' => $request->ulid,
            ])
            ->log('Staff started a data export on behalf of a user');

        return $request;
    }

    public static function ability(): string
    {
        return self::PERMISSION.' '.PermissionContext::Users->value;
    }
}
