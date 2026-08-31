<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\SubscriptionResource;
use Override;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
