<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Roles;

use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Roles\Pages\CreateRole;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Roles\Pages\EditRole;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Roles\Pages\ListRoles;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\TeamCluster;
use Nvade\Numerosis\Models\Role;
use Override;

class RoleResource extends \Nvade\Numerosis\Filament\App\Resources\Roles\RoleResource
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
