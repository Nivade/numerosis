<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Users;

use BackedEnum;
use Filament\Actions\Action as GlobalAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\Eloquent\Builder;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Tenancy\MembershipsFeature;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Users\Pages\EditUser;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Users\Pages\ListUsers;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\TeamCluster;
use Nvade\NumerosisFilament\TenantAdmin\Resources\BaseResource;
use Override;
use UnitEnum;

class UserResource extends BaseResource
{
    protected static ?string $cluster = TeamCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    #[Override]
    public static function getModel(): string
    {
        return Numerosis::model(User::class);
    }

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 0;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Members';
    }

    /**
     * This resource's directory is under TenantAdminPanelProvider's
     * ->discoverResources() scan, so discovery registers it regardless of
     * any array membership — this override is the actual gate, same trap
     * documented on Nvade\NumerosisFilament\TenantAdmin\Pages\Modules\Marketplace and
     * Nvade\NumerosisFilament\TenantAdmin\Resources\Modules\ModuleResource.
     */
    #[Override]
    public static function canAccess(): bool
    {
        return Features::enabled(MembershipsFeature::NAME) && parent::canAccess();
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return Features::enabled(MembershipsFeature::NAME) && parent::shouldRegisterNavigation();
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('User Information')
                ->description('Basic information about this team member')
                ->icon(Heroicon::OutlinedUser)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('John Doe')
                        ->helperText('Full name as it will appear to teammates.')
                        ->columnSpanFull(),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->placeholder('john@example.com')
                        ->helperText('We\'ll use this to send notifications and password resets.')
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Security')
                ->description('Set password and authentication credentials')
                ->icon(Heroicon::OutlinedLockClosed)
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): string => $operation === 'create' ? 'Must be at least 8 characters long.' : 'Leave blank to keep the current password.')
                        ->label('Password')
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Roles & Permissions')
                ->description('Control what this user can access and do')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    Select::make('roles')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText('Assign one or more roles to control access. Users inherit all permissions from their roles.')
                        ->label('Roles')
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->collapsible()
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
                    ->label('Member')
                    ->icon(Heroicon::OutlinedUser)
                    ->description(fn (User $record): string => $record->email),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(',')
                    ->sortable()
                    ->searchable()
                    ->icon(Heroicon::OutlinedKey),

                TextColumn::make('email_verified_at')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => $record->hasVerifiedEmail() ? 'Verified' : 'Pending Verification')
                    ->color(fn (User $record): string => $record->email_verified_at ? 'success' : 'warning')
                    ->icon(fn (User $record): Heroicon => $record->email_verified_at ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationCircle),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->label('Joined')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->relationship('roles', 'name')
                    ->label('Filter by Role')
                    ->multiple()
                    ->preload(),

                TernaryFilter::make('email_verified')
                    ->label('Email Verification Status')
                    ->placeholder('All members')
                    ->trueLabel('Verified only')
                    ->falseLabel('Unverified only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('email_verified_at'),
                        blank: fn (Builder $query) => $query,
                    ),

                Filter::make('created_at')
                    ->label('Joined Date Range')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From')
                            ->placeholder('Start date'),
                        DatePicker::make('until')
                            ->label('Until')
                            ->placeholder('End date'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Joined from '.date('M d, Y', strtotime($data['from']));
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Joined until '.date('M d, Y', strtotime($data['until']));
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencil)
                    ->label('Edit'),

                GlobalAction::make('sendReset')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->label('Send Password Reset')
                    ->color('gray')
                    // The reset email links to password.reset, which only
                    // exists while PasswordResetFeature is enabled — sending
                    // one anyway would hand the user a link that 404s.
                    ->visible(fn (): bool => Features::enabled(PasswordResetFeature::NAME))
                    ->action(function (User $record): void {
                        resolve(PasswordBroker::class)->sendResetLink(['email' => $record->email]);

                        Notification::make()
                            ->title('Password reset link sent')
                            ->body("A password reset email has been sent to {$record->email}")
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Send Password Reset Link')
                    ->modalDescription(fn (User $record): string => "This will send a password reset link to {$record->email}. The user will receive an email with instructions to reset their password.")
                    ->modalIcon(Heroicon::OutlinedEnvelope)
                    ->modalIconColor('info'),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateHeading('No team members yet')
            ->emptyStateDescription('Get started by adding your first team member.');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
