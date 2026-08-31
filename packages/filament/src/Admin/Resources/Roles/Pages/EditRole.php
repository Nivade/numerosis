<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Roles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\NumerosisFilament\Admin\Resources\Roles\RoleResource;
use Override;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Every user holding this role loses the permissions it grants immediately. This does not delete those users.'),
        ];
    }
}
