<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\PlanFeatureResource;
use Override;

class ListPlanFeatures extends ListRecords
{
    protected static string $resource = PlanFeatureResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
