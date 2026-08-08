<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\App\Resources\Roles;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\BaseResource;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Override;

class RoleResource extends BaseResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        $group = config('permission.filament.roles.navigation.group', config('permission.filament.navigation.group'));

        return is_string($group) ? $group : null;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        $label = config('permission.filament.roles.navigation.label');

        return is_string($label) ? $label : 'Roles';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role Details')
                ->columnSpanFull()
                ->description('Define the basic information for this role')
                ->icon(Heroicon::OutlinedIdentification)
                ->schema([
                    Grid::make()
                        ->schema([
                            TextInput::make('name')
                                ->label('Role Name')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255)
                                ->placeholder('e.g., Manager, Editor, Viewer')
                                ->helperText('A unique, descriptive name for this role')
                                ->live(onBlur: true)
                                ->columnSpan([
                                    'default' => 12,
                                    'md' => 6,
                                ]),

                            Select::make('guard_name')
                                ->label('Authentication Guard')
                                ->required()
                                ->default('tenant')
                                ->options([
                                    'web' => 'Web',
                                    'tenant' => 'Tenant',
                                ])
                                ->helperText('Select the authentication guard for this role')
                                ->columnSpan([
                                    'default' => 12,
                                    'md' => 6,
                                ]),
                        ])
                        ->columns(12),
                ]),

            Section::make('Permissions')
                ->description('Select the permissions for this role. Use the toggles to quickly enable/disable all permissions in a category.')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->headerActions([
                    Action::make('selectAll')
                        ->label('Select All')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->action(function ($livewire) {
                            $allPermissions = Permission::pluck('id')->toArray();
                            $livewire->data['permissions'] = $allPermissions;
                        }),
                    Action::make('deselectAll')
                        ->label('Deselect All')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->action(function ($livewire) {
                            $livewire->data['permissions'] = [];
                        }),
                ])
                ->schema([
                    Grid::make()
                        ->schema(
                            static::getPermissionCheckboxes()
                        )
                        ->columns([
                            'default' => 1,
                            'sm' => 1,
                            'md' => 2,
                            'lg' => 3,
                            'xl' => 4,
                        ]),
                ])
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(false),
        ]);
    }

    /**
     * @return array<int, Component>
     */
    protected static function getPermissionCheckboxes(): array
    {
        $permissions = Permission::all()
            ->groupBy(fn (Permission $permission) => $permission->context);

        $components = [];

        foreach ($permissions as $context => $contextPermissions) {
            $contextTitle = Str::title($context);
            $permissionCount = $contextPermissions->count();

            // Group permissions by ability (action type)
            $groupedByAbility = $contextPermissions->groupBy(fn (Permission $permission) => $permission->ability);

            // Build options with better labels showing both ability and context
            $options = $contextPermissions->mapWithKeys(fn (Permission $permission) => [
                $permission->id => Str::title(str_replace('_', ' ', $permission->ability)),
            ]);

            $components[] = Section::make($contextTitle)
                ->description("Manage {$permissionCount} permissions for {$contextTitle}")
                ->icon(static::getContextIcon($context))
                ->schema([
                    CheckboxList::make($context.'_permissions')
                        ->relationship(
                            name: 'permissions',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query->where('context', $context)
                        )
                        ->options($options)
                        ->columns([
                            'default' => 1,
                            'sm' => 2,
                        ])
                        ->gridDirection('row')
                        ->bulkToggleable()
                        ->searchable()
                        ->label(''),
                ])
                ->compact()
                ->collapsible()
                ->collapsed(true);
        }

        return $components;
    }

    protected static function getContextIcon(string $context): Heroicon
    {
        return match (Str::lower($context)) {
            'user', 'users' => Heroicon::OutlinedUsers,
            'role', 'roles' => Heroicon::OutlinedKey,
            'permission', 'permissions' => Heroicon::OutlinedShieldCheck,
            'client', 'clients' => Heroicon::OutlinedBriefcase,
            'invitation', 'invitations' => Heroicon::OutlinedEnvelope,
            'tenant', 'tenants' => Heroicon::OutlinedBuildingLibrary,
            'settings' => Heroicon::OutlinedCog6Tooth,
            'report', 'reports' => Heroicon::OutlinedChartBar,
            default => Heroicon::OutlinedFolderOpen,
        };
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns(components: [
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon(Heroicon::OutlinedKey)
                    ->iconColor('primary')
                    ->description(fn (Role $record): string => $record->guard_name === 'tenant' ? 'Tenant Role' : 'Web Role')
                    ->label('Role Name'),

                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Permissions')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state === 0 => 'gray',
                        $state < 5 => 'warning',
                        $state < 10 => 'info',
                        default => 'success',
                    })
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn (Role $record): string => $record->permissions()->count().' permissions assigned'),

                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn (Role $record): string => $record->users()->count().' users with this role'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('guard_name')
                    ->label('Guard')
                    ->options([
                        'web' => 'Web',
                        'tenant' => 'Tenant',
                    ]),
                Filter::make('has_permissions')
                    ->label('With Permissions')
                    ->query(fn ($query) => $query->has('permissions')),
                Filter::make('has_users')
                    ->label('With Users')
                    ->query(fn ($query) => $query->has('users')),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
                DeleteBulkAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateIcon(Heroicon::OutlinedKey)
            ->emptyStateHeading('No roles defined')
            ->emptyStateDescription('Create your first role to start managing permissions and access control.');
    }
}
