<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Hash;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Auth\CreatesRegisteredUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Numerosis;

/**
 * @method static CentralUser run(array{name: string, email: string, password: string} $data)
 */
class CreateRegisteredUser implements CreatesRegisteredUser
{
    use AsAction;

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function handle(array $data): CentralUser
    {
        return $this->create($data);
    }

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function create(array $data): CentralUser
    {
        $data['password'] = Hash::make($data['password']);

        $centralUserClass = Numerosis::model(CentralUser::class);

        return $centralUserClass::create($data);
    }
}
