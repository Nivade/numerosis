<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\RoleResource;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Every user holding this role loses the permissions it grants immediately. This does not delete those users.'),
        ];
    }
}
