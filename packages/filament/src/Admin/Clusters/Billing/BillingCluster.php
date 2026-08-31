<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Clusters\Billing;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class BillingCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
