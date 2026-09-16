<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Events\Auth\TwoFactorAuthenticationCleared;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * The support path for a lost device. Without it every lost phone is a
 * database edit.
 *
 * @method static void run(CentralUser $staff, CentralUser $target)
 */
class ClearTwoFactorAuthentication
{
    use AsAction;

    /**
     * Seeded on the central guard by
     * {@see \Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder}, apart
     * from the CRUD actions: clearing a factor is not part of administering a
     * user record.
     */
    public const string PERMISSION = 'clearTwoFactor';

    public function __construct(private readonly DisableTwoFactorAuthentication $disable) {}

    public function handle(CentralUser $staff, CentralUser $target): void
    {
        ($this->disable)($target);

        event(new TwoFactorAuthenticationCleared($staff->global_id, $target->global_id));
    }

    public static function ability(): string
    {
        return self::PERMISSION.' '.PermissionContext::Users->value;
    }
}
