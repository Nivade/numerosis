<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages;

use Nvade\Numerosis\Actions\Auth\DisconnectSocialAccount;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\ProfileCluster;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * @property-read Schema $form
 */
class SocialAccounts extends Page implements HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    #[Locked]
    public ?User $record = null;

    protected static ?int $navigationSort = 200;

    protected static ?string $cluster = ProfileCluster::class;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedLink;

    protected string $view = 'filament.tenant-admin.clusters.profile.pages.generic';

    public function mount(): void
    {
        $this->record = GetAuthenticatedUser::run();

        $this->form->fill();
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
                Section::make('Connected Accounts')
                    ->description('Manage your connected social accounts for easy sign-in.')
                    ->icon('heroicon-o-share')
                    ->schema([
                        ViewField::make('social_accounts')
                            ->view('filament.tenant-admin.components.social-accounts-manager')
                            ->viewData(fn (): array => [
                                'currentUrl' => url()->current(),
                            ])
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    public function disconnectSocialAccountAction(): Action
    {
        return Action::make('disconnectSocialAccount')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Disconnect '.ucfirst($arguments['provider']).'?')
            ->modalDescription(fn (array $arguments): string => "Are you sure you want to disconnect your {$arguments['provider']} account? You will no longer be able to sign in using this provider.")
            ->modalSubmitActionLabel('Disconnect')
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->action(function (array $arguments): void {
                /** @var CentralUser $centralUser */
                $centralUser = Auth::guard('web')->user();

                DisconnectSocialAccount::run($centralUser, $arguments['provider']);

                Notification::make()
                    ->title('Social account disconnected')
                    ->body("Your {$arguments['provider']} account has been successfully disconnected.")
                    ->success()
                    ->send();
            });
    }
}
