<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Features\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Central\Features\FeatureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeature extends CreateRecord
{
    protected static string $resource = FeatureResource::class;
}
