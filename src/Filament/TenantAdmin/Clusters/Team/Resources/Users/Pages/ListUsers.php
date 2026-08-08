<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Users\Pages;

use Filament\Resources\Pages\ListRecords;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Users\UserResource;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
