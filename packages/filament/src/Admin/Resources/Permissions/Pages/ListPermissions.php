<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Permissions\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\Admin\Resources\Permissions\PermissionResource;
use Override;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
