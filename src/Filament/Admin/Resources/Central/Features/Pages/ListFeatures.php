<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Features\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Features\FeatureResource;
use Override;

class ListFeatures extends ListRecords
{
    protected static string $resource = FeatureResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
