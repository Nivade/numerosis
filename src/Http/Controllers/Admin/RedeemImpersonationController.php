<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Actions\Admin\RedeemImpersonation;
use Nvade\Numerosis\Http\Controllers\Controller;

class RedeemImpersonationController extends Controller
{
    public function __invoke(string $token): RedirectResponse
    {
        return RedeemImpersonation::run($token);
    }
}
