<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\PlanFeatureResource;

class CreatePlanFeature extends CreateRecord
{
    protected static string $resource = PlanFeatureResource::class;
}
