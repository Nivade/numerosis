<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Nvade\Numerosis\Policies\ModulePolicy;

#[UsePolicy(ModulePolicy::class)]
class Module extends \Nvade\Numerosis\Models\Tenant\Module {}
