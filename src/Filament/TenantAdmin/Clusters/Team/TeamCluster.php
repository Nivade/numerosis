<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

class TeamCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Team Management';

    protected static ?string $clusterBreadcrumb = 'Team';

    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Manage your team members, roles, and permissions';
    }
}
