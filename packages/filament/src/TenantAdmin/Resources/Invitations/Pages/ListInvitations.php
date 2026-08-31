<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Invitations\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Invitations\InvitationResource;
use Override;

class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
