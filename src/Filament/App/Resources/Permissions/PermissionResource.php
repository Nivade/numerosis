<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\App\Resources\Permissions;

use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\BaseResource;
use Nvade\Numerosis\Models\Permission;
use Override;

class PermissionResource extends BaseResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        $group = config(
            'numerosis.panels.access_control.permissions.navigation_group',
            config('numerosis.panels.access_control.navigation_group'),
        );

        return is_string($group) ? $group : null;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return Config::string('numerosis.panels.access_control.permissions.navigation_label', 'Permissions');
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Permission Details')
                ->description('Define a specific permission that can be assigned to roles')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    Grid::make()
                        ->schema([
                            TextInput::make('name')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->label('Permission Name')
                                ->placeholder('e.g., view_posts, edit_users, delete_comments')
                                ->helperText('Use format: action_resource (e.g., view_posts, edit_users)')
                                ->live(onBlur: true)
                                ->columnSpan([
                                    'default' => 12,
                                    'md' => 8,
                                ]),

                            Select::make('guard_name')
                                ->options(new Collection(Config::array('auth.guards'))->keys())
                                ->default('tenant')
                                ->required()
                                ->label('Authentication Guard')
                                ->helperText('Select the authentication guard')
                                ->columnSpan([
                                    'default' => 12,
                                    'md' => 4,
                                ]),
                        ])
                        ->columns(12),
                ])
                ->columnSpanFull(),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->iconColor('primary')
                    ->description(fn ($record): string => 'Context: '.Str::title($record->context ?? 'N/A'))
                    ->label('Permission Name')
                    ->copyable()
                    ->copyMessage('Permission name copied')
                    ->copyMessageDuration(1500),

                TextColumn::make('ability')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'delete') || str_contains($state, 'force') => 'danger',
                        str_contains($state, 'create') || str_contains($state, 'update') => 'warning',
                        str_contains($state, 'view') => 'success',
                        default => 'info',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('context')
                    ->label('Resource')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => Str::title($state)),

                TextColumn::make('roles_count')
                    ->counts('roles')
                    ->label('Roles')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record): string => $record->roles()->count().' roles assigned'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('guard_name')
                    ->label('Guard')
                    ->options(new Collection(Config::array('auth.guards'))->keys()),
                SelectFilter::make('context')
                    ->label('Resource')
                    ->options(fn (): array => self::contextFilterOptions()),
                SelectFilter::make('ability')
                    ->label('Action')
                    ->options(fn (): array => self::abilityFilterOptions()),
                Filter::make('assigned')
                    ->label('Assigned to Roles')
                    ->query(fn ($query) => $query->has('roles')),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->groupedBulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('name')
            ->groups([
                Group::make('context')
                    ->label('Resource')
                    ->collapsible(),
                Group::make('ability')
                    ->label('Action')
                    ->collapsible(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('No permissions defined')
            ->emptyStateDescription('Create your first permission to control access to specific features and resources.');
    }

    /**
     * Shared with {@see \Nvade\Numerosis\Filament\App\Resources\Roles\RelationManagers\PermissionsRelationManager},
     * which filters the same column with the same labels.
     *
     * @return array<string, string>
     */
    public static function contextFilterOptions(): array
    {
        return self::distinctColumn('context')
            ->map(fn (string $context): string => Str::title($context))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function abilityFilterOptions(): array
    {
        return self::distinctColumn('ability')
            ->map(fn (string $ability): string => Str::title(str_replace('_', ' ', $ability)))
            ->all();
    }

    /**
     * Distinct values of a permission column, keyed by themselves so the result
     * drops straight into a SelectFilter's options.
     *
     * @return Collection<string, string>
     */
    private static function distinctColumn(string $column): Collection
    {
        /** @var Collection<string, string> $values */
        $values = Permission::query()->pluck($column, $column)->unique();

        return $values;
    }
}
