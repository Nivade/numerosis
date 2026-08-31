<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Roles\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\Admin\Resources\Roles\RoleResource;
use Override;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
