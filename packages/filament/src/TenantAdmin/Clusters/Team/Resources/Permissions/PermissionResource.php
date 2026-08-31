<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions;

use Nvade\Numerosis\Models\Permission;
use Nvade\NumerosisFilament\App\Resources\Permissions\PermissionResource as BasePermissionResource;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages\CreatePermission;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages\EditPermission;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages\ListPermissions;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\RelationManagers\RolesRelationManager;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\TeamCluster;
use Override;

class PermissionResource extends BasePermissionResource
{
    protected static ?string $model = Permission::class;

    protected static ?string $cluster = TeamCluster::class;

    protected static ?int $navigationSort = 200;

    #[Override]
    public static function getRelations(): array
    {
        return [
            RolesRelationManager::class,
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'edit' => EditPermission::route('/{record}/edit'),
        ];
    }
}
