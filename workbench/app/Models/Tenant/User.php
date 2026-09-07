<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Nvade\Numerosis\Policies\Auth\UserPolicy;

#[UsePolicy(UserPolicy::class)]
class User extends \Nvade\Numerosis\Models\Tenant\User {}
