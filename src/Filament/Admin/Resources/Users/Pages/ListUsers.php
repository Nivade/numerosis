<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Users\Pages;

use Filament\Resources\Pages\ListRecords;
use Nvade\Numerosis\Filament\Admin\Resources\Users\UserResource;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
