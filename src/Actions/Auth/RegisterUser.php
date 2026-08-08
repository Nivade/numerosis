<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Auth\CreatesRegisteredUser;
use Nvade\Numerosis\Models\Central\CentralUser;

class RegisterUser
{
    use AsAction;

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function handle(array $data): CentralUser
    {
        $user = resolve(CreatesRegisteredUser::class)->create($data);

        Event::dispatch(new Registered($user));

        LoginUser::run($user);

        return $user;
    }
}
