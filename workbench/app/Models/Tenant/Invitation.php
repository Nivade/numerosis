<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Nvade\Numerosis\Policies\InvitationPolicy;

#[UsePolicy(InvitationPolicy::class)]
class Invitation extends \Nvade\Numerosis\Models\Tenant\Invitation {}
