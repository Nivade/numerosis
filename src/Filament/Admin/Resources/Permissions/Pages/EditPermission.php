<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Permissions\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\Numerosis\Filament\Admin\Resources\Permissions\PermissionResource;
use Override;

class EditPermission extends EditRecord
{
    protected static string $resource = PermissionResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Every role granting this permission loses it immediately, and every user who only had access through this permission loses it too.'),
        ];
    }
}
