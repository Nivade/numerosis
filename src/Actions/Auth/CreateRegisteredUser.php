<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\RegistrationData;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Numerosis;

/**
 * Fortify's `CreatesNewUsers` slot, bound via `Fortify::createUsersUsing()`
 * in `NumerosisServiceProvider::packageBooted()`. `RegisteredUserController`
 * validates nothing itself, so `create()` must, which `RegistrationData` does
 * on entry, converting the untyped `array $input` Fortify's contract requires
 * into a typed object at the boundary.
 *
 * @method static CentralUser run(array<string, mixed> $input)
 */
class CreateRegisteredUser implements CreatesNewUsers
{
    use AsAction;

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function handle(array $input): CentralUser
    {
        return $this->create($input);
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function create(array $input): CentralUser
    {
        $data = RegistrationData::validateAndCreate($input);

        $centralUserClass = Numerosis::model(CentralUser::class);

        $user = $centralUserClass::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => Hash::make($data->password),
        ]);

        RecordConsent::run($user);

        return $user;
    }
}
