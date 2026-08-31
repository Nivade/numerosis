<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Profile;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ProfileCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $clusterBreadcrumb = 'Profile';

    protected static ?string $navigationLabel = 'Profile';

    protected static string|null|UnitEnum $navigationGroup = 'Settings';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;

    protected static bool $shouldRegisterNavigation = false;
}
