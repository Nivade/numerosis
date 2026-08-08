<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Actions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Nvade\Numerosis\Actions\Auth\LogoutUser;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke(): RedirectResponse
    {
        LogoutUser::run();

        return Redirect::to('/');
    }
}
