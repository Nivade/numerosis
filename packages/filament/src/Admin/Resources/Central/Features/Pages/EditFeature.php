<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Features\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\NumerosisFilament\Admin\Resources\Central\Features\FeatureResource;
use Override;

class EditFeature extends EditRecord
{
    protected static string $resource = FeatureResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
