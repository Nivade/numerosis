<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Invitations;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Nvade\Numerosis\Events\Invitations\InvitationIssued;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\BaseResource;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Invitations\Pages\CreateInvitation;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Invitations\Pages\ListInvitations;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Override;

class InvitationResource extends BaseResource
{
    protected static ?string $recordTitleAttribute = 'email';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Team Invitations';

    protected static ?string $modelLabel = 'Invitation';

    #[Override]
    public static function getModel(): string
    {
        return Numerosis::model(Invitation::class);
    }

    #[Override]
    public static function canAccess(): bool
    {
        return Features::enabled(InvitationsFeature::NAME) && parent::canAccess();
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return Features::enabled(InvitationsFeature::NAME) && parent::shouldRegisterNavigation();
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->label('Email Address')
                    ->helperText('Enter the email address of the person you want to invite'),

                Select::make('role')
                    ->options([
                        'member' => 'Member',
                        'admin' => 'Admin',
                    ])
                    ->default('member')
                    ->required()
                    ->label('Role')
                    ->helperText('Select the role for this team member'),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->label('Email'),

                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'warning',
                        'member' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('inviter.name')
                    ->label('Invited By')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Sent At')
                    ->since(),

                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Expires')
                    ->since(),

                TextColumn::make('status')
                    ->badge()
                    ->label('Status')
                    ->getStateUsing(function (Invitation $record): string {
                        if ($record->isAccepted()) {
                            return 'accepted';
                        }
                        if ($record->isExpired()) {
                            return 'expired';
                        }

                        return 'pending';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'expired' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'accepted' => 'Accepted',
                        'expired' => 'Expired',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (! isset($data['value'])) {
                            return $query;
                        }

                        return match ($data['value']) {
                            'pending' => $query->whereNull('accepted_at')->where('expires_at', '>', now()),
                            'accepted' => $query->whereNotNull('accepted_at'),
                            'expired' => $query->whereNull('accepted_at')->where('expires_at', '<=', now()),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('resend')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('info')
                    ->visible(fn (Invitation $record) => $record->isPending())
                    ->action(function (Invitation $record) {
                        // Update expiration
                        $record->update(['expires_at' => now()->addDays(7)]);

                        event(new InvitationIssued($record));

                        Notification::make()
                            ->title('Invitation resent successfully')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Resend Invitation')
                    ->modalDescription('Are you sure you want to resend this invitation?'),

                DeleteAction::make()
                    ->visible(fn (Invitation $record) => ! $record->isAccepted()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInvitations::route('/'),
            'create' => CreateInvitation::route('/create'),
        ];
    }
}
