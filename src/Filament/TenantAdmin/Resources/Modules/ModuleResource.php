<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Actions\Modules\CancelModule;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedTenantUser;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Filament\Concerns\NotifiesUser;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\BaseResource;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules\Pages\ListModules;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Override;

class ModuleResource extends BaseResource
{
    use NotifiesUser;

    protected static ?string $slug = 'modules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[Override]
    public static function getModel(): string
    {
        return Numerosis::model(Module::class);
    }

    #[Override]
    public static function canAccess(): bool
    {
        return Features::enabled(ModuleSystemFeature::NAME) && parent::canAccess();
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return Features::enabled(ModuleSystemFeature::NAME) && parent::shouldRegisterNavigation();
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Modules';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Settings';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name'),

                TextInput::make('description'),

                Checkbox::make('enabled'),

                DatePicker::make('purchased_at')
                    ->label('Purchased Date'),

                TextEntry::make('created_at')
                    ->label('Created Date')
                    ->state(fn (?Module $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                TextEntry::make('updated_at')
                    ->label('Last Modified Date')
                    ->state(fn (?Module $record): string => $record?->updated_at?->diffForHumans() ?? '-'),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->query(fn (Builder $query): Builder => Numerosis::model(Module::class)::query()->whereNotNull('purchased_at'))
            ->columns([
                Stack::make([
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable()
                        ->weight('bold')
                        ->size('lg'),

                    TextColumn::make('description')
                        ->color('gray')
                        ->limit(100),

                    IconColumn::make('enabled')
                        ->boolean(),

                    TextColumn::make('purchased_at')
                        ->label('Purchased Date')
                        ->date()
                        ->color('gray')
                        ->size('sm'),
                ])->space(3),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->requiresConfirmation()
                    ->modalDescription('This stops billing for the module and disables it. Any data it stored is kept.')
                    ->visible(fn (Module $record): bool => $record->enabled)
                    // Authorized against the tenant guard by name rather than
                    // the ambient default, which anything in the request may
                    // have moved — a central user resolved here holds no
                    // tenant roles and would be refused.
                    ->authorize(function (Module $record): bool {
                        $actor = GetAuthenticatedTenantUser::run();

                        return $actor !== null && Gate::forUser($actor)->allows('cancel', $record);
                    })
                    ->action(function (Module $record, ListModules $livewire): void {
                        $tenant = tenant();
                        $actor = GetAuthenticatedTenantUser::run();

                        if (! $tenant instanceof Tenant || $actor === null) {
                            self::notifyError(__('numerosis::billing.modules.cancel_unavailable'));

                            return;
                        }

                        try {
                            CancelModule::run($tenant, $actor, $record->name);
                        } catch (ShowsMessageToUser $e) {
                            self::notifyDomainError($e);

                            return;
                        }

                        $livewire->dispatch('refresh-sidebar');

                        self::notifySuccess('Module cancelled');
                    }),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ]);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListModules::route('/'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }
}
