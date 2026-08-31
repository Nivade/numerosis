<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Features\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\Admin\Resources\Central\Features\FeatureResource;

class CreateFeature extends CreateRecord
{
    protected static string $resource = FeatureResource::class;
}
