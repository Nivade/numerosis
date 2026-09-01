<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Roles;

use Nvade\Numerosis\Models\Role;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Roles\Pages\CreateRole;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Roles\Pages\EditRole;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Roles\Pages\ListRoles;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\TeamCluster;
use Override;

class RoleResource extends \Nvade\NumerosisFilament\Shared\Resources\Roles\RoleResource
{
    protected static ?string $model = Role::class;

    protected static ?string $cluster = TeamCluster::class;

    protected static ?int $navigationSort = 100;

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
