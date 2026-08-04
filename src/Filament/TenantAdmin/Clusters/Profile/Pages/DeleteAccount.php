<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages;

use Nvade\Numerosis\Actions\Auth\DeleteUserAccount;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\ProfileCluster;
use Nvade\Numerosis\Models\Central\CentralUser;
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
use Illuminate\Support\Facades\Session;

class DeleteAccount extends Page implements HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?int $navigationSort = 400;

    protected static ?string $cluster = ProfileCluster::class;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedTrash;

    protected string $view = 'filament.tenant-admin.clusters.profile.pages.generic';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Delete Account')
                    ->description('Permanently delete your account and all associated data. This action cannot be undone.')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        ViewField::make('delete_account')
                            ->view('filament.tenant-admin.components.delete-account-panel')
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function deleteAccountAction(): Action
    {
        return Action::make('deleteAccount')
            ->requiresConfirmation()
            ->modalHeading('Delete Account?')
            ->modalDescription('Are you sure you want to permanently delete your account? All of your data will be permanently deleted and this action cannot be undone.')
            ->modalSubmitActionLabel('Delete Account')
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->action(function (CentralUser $user): void {
                if (DeleteUserAccount::run($user)) {
                    Auth::logout();
                    Session::invalidate();
                    Session::regenerateToken();

                    $this->redirect('/');
                } else {
                    Notification::make()
                        ->title('Account deletion failed')
                        ->body('You cannot delete your account while you are the owner of one or more tenants. Please transfer ownership or delete the tenants first.')
                        ->danger()
                        ->send();
                }
            });
    }
}
