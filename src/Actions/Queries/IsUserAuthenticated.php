<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsAction;

class IsUserAuthenticated
{
    use AsAction;

    public function handle(?string $guard = null): bool
    {
        return $guard ? Auth::guard($guard)->check() : Auth::check();
    }
}
