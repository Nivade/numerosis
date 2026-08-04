<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Nvade\Numerosis\Models\Central\CentralUser;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SocialAccountDisconnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CentralUser $user,
        public string $provider,
    ) {}
}
