<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Users\Pages;

use Filament\Resources\Pages\EditRecord;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;
}
