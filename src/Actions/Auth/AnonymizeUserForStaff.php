<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * Erasure on behalf of a subject who asked by mail. Ownership still blocks:
 * an owner has to transfer the workspace or close it first, which is what
 * `tenancy:transfer-ownership` and the closure flow are for.
 *
 * @method static bool run(CentralUser $staff, CentralUser $subject)
 */
class AnonymizeUserForStaff
{
    use AsAction;

    public const string PERMISSION = 'eraseData';

    public function handle(CentralUser $staff, CentralUser $subject): bool
    {
        $erased = DeleteUserAccount::run($subject);

        activity()
            ->causedBy($staff)
            ->withProperties([
                'subject_global_id' => $subject->global_id,
                'erased' => $erased,
            ])
            ->log($erased
                ? 'Staff erased a user on request'
                : 'Staff could not erase a user: they still own a workspace');

        return $erased;
    }

    public static function ability(): string
    {
        return self::PERMISSION.' '.PermissionContext::Users->value;
    }
}
