<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions;

use Nvade\Numerosis\Filament\App\Resources\Permissions\PermissionResource as BasePermissionResource;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages\CreatePermission;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages\EditPermission;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages\ListPermissions;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\RelationManagers\RolesRelationManager;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\TeamCluster;
use Nvade\Numerosis\Models\Permission;

class PermissionResource extends BasePermissionResource
{
    protected static ?string $model = Permission::class;

    protected static ?string $cluster = TeamCluster::class;

    protected static ?int $navigationSort = 200;

    public static function getRelations(): array
    {
        return [
            RolesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'edit' => EditPermission::route('/{record}/edit'),
        ];
    }
}
