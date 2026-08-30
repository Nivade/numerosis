<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Tenants;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvade\Numerosis\Actions\Tenancy\ImpersonateTenantUser;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Features\Tenancy\ImpersonationFeature;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\RelationManagers\DomainsRelationManager;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use UnitEnum;

class TenantResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    #[Override]
    public static function getModel(): string
    {
        return Numerosis::model(Tenant::class);
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextInput::make('name')
                        ->required()
                        ->unique(column: 'data->name', ignoreRecord: true)
                        ->afterStateUpdated(function (Set $set, $state) {
                            $set('id', $slug = Str::of($state)->slug('_')->toString());
                            $set('domain', Str::of($state)->slug()->toString());
                        })->columnSpanFull(),
                    TextInput::make('id')
                        ->label('Unique ID')
                        ->required()
                        ->disabled(fn ($context) => $context !== 'create')
                        ->unique(table: 'tenants', ignoreRecord: true),
                    TextInput::make('domain')
                        ->label(IdentificationMode::current() === IdentificationMode::Subdomain ? 'Sub-Domain' : 'Domain')
                        ->required()
                        ->visible(fn ($context) => $context === 'create')
                        ->unique(table: 'domains', ignoreRecord: true)
                        ->prefix(IdentificationMode::current() === IdentificationMode::Subdomain ? 'https://' : null)
                        ->suffix(IdentificationMode::current() === IdentificationMode::Subdomain ? '.'.Config::string('numerosis.domains.apex') : null),
                    TextInput::make('email')->email(),
                    TextInput::make('phone')->tel(),
                    TextInput::make('mobile')->tel(),
                    ColorPicker::make('primary_color'),
                    ColorPicker::make('secondary_color'),
                ])->columns(),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name'),
                // 'Stuck' separates a signup still provisioning (normal, and
                // over in seconds) from one whose provisioning was dropped.
                // Both otherwise read as 'Provisioning', with no signal that
                // one of them needs a human.
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function (Tenant $record): string {
                        if ($record->isSuspended()) {
                            return 'Suspended';
                        }

                        if ($record->provisioned_at !== null) {
                            return 'Active';
                        }

                        return $record->created_at?->lt(now()->subMinutes(10))
                            ? 'Stuck'
                            : 'Provisioning';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Suspended', 'Stuck' => 'danger',
                        'Provisioning' => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (string $state): ?string => $state === 'Stuck'
                        ? 'Still unprovisioned more than 10 minutes after creation — the provisioning chain likely failed partway through.'
                        : null),
                TextColumn::make('suspended_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No tenants yet')
            ->emptyStateDescription('Tenants are created through the registration wizard, not this table — see "New Tenant" above.')
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->filters([
                TernaryFilter::make('suspended_at')
                    ->label('Suspended')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('suspended_at'),
                        false: fn ($query) => $query->whereNull('suspended_at'),
                    ),
                TernaryFilter::make('provisioned_at')
                    ->label('Provisioned')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('provisioned_at'),
                        false: fn ($query) => $query->whereNull('provisioned_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // Manual counterparts to the suspension the Stripe webhook
                // performs, for pausing a tenant for abuse or reactivating
                // one without waiting on Stripe. Both are idempotent.
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->visible(fn (Tenant $record): bool => ! $record->isSuspended())
                    ->requiresConfirmation()
                    ->modalDescription('The tenant is immediately locked out of their workspace. Existing data is untouched.')
                    ->action(fn (Tenant $record) => SuspendTenant::run($record)),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record): bool => $record->isSuspended())
                    ->requiresConfirmation()
                    ->action(fn (Tenant $record) => RestoreTenant::run($record)),
                Action::make('impersonate')
                    ->label('Impersonate owner')
                    ->icon('heroicon-o-user-circle')
                    ->color('gray')
                    ->visible(fn (Tenant $record): bool => Features::enabled(ImpersonationFeature::NAME)
                        && $record->provisioned_at !== null
                        && ! $record->isSuspended()
                        && $record->owner() !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Opens a new browser session logged in as the tenant\'s owner, for support. The link expires in 60 seconds and can only be used once.')
                    ->action(fn (Tenant $record) => ImpersonateTenantUser::run($record)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // Custom copy, because the default modal would let an
                    // operator assume this is a full teardown. It deletes the
                    // tenant record only; the database is left orphaned.
                    DeleteBulkAction::make()
                        ->modalHeading('Delete selected tenants?')
                        ->modalDescription('This removes the tenant record and its domains only. The physical tenant database is not dropped — it becomes orphaned and is swept later by tenancy:prune-orphaned-databases, not immediately.'),
                ]),
            ]);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            DomainsRelationManager::class,
        ];
    }

    /**
     * Support's most common lookup is the subdomain a customer reports, not
     * the internal id — 'domains.domain' is what makes that findable at all,
     * since it's not a column on tenants itself.
     *
     * @return array<int, string>
     */
    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'name', 'domains.domain'];
    }

    #[Override]
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('domains');
    }

    /**
     * No 'create' route: see {@see ListTenants}
     * for why tenant creation is not a Filament CreateRecord page here.
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}
