<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Filament\Concerns\InteractsWithRecord;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\ProfileCluster;
use Nvade\Numerosis\Models\User;

/**
 * @property-read Schema $form
 */
class Security extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static ?int $navigationSort = 100;

    protected static ?string $cluster = ProfileCluster::class;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected string $view = 'numerosis::filament.tenant-admin.clusters.profile.pages.generic';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->record = GetAuthenticatedUser::run();

        $this->form->fill($this->user()->attributesToArray());
    }

    /**
     * The authenticated user this page edits.
     */
    protected function user(): User
    {
        $user = $this->record;

        abort_unless($user instanceof User, 404);

        return $user;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Update Password')
                    ->description('Ensure your account is using a long, random password to stay secure.')
                    ->icon('heroicon-o-lock-closed')
                    ->schema([
                        TextInput::make('current_password')
                            ->label('Current Password')
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required(fn () => filled($this->user()->password))
                            ->currentPassword()
                            ->visible(fn () => filled($this->user()->password))
                            ->helperText('Enter your current password to confirm changes.'),
                        TextInput::make('password')
                            ->label('New Password')
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required()
                            ->confirmed()
                            ->minLength(8)
                            ->autoComplete('new-password')
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Must be at least 8 characters long.'),
                        TextInput::make('password_confirmation')
                            ->label('Confirm New Password')
                            ->password()
                            ->required()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->dehydrated(false),
                    ])
                    ->footerActions($this->getFormActions())
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Update Password')
                ->icon(Heroicon::OutlinedLockClosed)
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if (filled($data['password'])) {
            $this->user()->update([
                'password' => $data['password'],
            ]);

            $this->form->fill();

            Notification::make()
                ->title('Password updated successfully')
                ->success()
                ->send();
        }
    }
}
