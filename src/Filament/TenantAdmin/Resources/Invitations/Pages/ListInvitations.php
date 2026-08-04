<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Invitations\Pages;

use Nvade\Numerosis\Filament\TenantAdmin\Resources\Invitations\InvitationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
