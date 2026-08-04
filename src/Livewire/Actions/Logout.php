<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Actions;

use Nvade\Numerosis\Actions\Auth\LogoutUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

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
