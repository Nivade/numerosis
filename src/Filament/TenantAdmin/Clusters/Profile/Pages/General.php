<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Icons\Heroicon;
use Nvade\Numerosis\Actions\Auth\ResendVerificationNotification;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Filament\Concerns\InteractsWithRecord;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\ProfileCluster;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Support\Numerosis;

/**
 * @property-read Schema $form
 */
class General extends Page implements HasForms
{
    use EvaluatesClosures;
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static ?int $navigationSort = 0;

    protected static ?string $cluster = ProfileCluster::class;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedUser;

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
            ->components([
                Section::make('Profile Information')
                    ->description("Update your account's profile information and email address.")
                    ->icon('heroicon-o-user')
                    ->schema([
                        TextInput::make('name')
                            ->label(__('filament-panels::pages/auth/edit-profile.form.name.label'))
                            ->required()
                            ->maxLength(255)
                            ->autofocus(),
                        TextInput::make('email')
                            ->label(__('filament-panels::pages/auth/edit-profile.form.email.label'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        ViewField::make('email_verified_at')
                            ->view('numerosis::filament.tenant-admin.components.email-verification-status')
                            ->columnSpanFull(),
                    ])
                    ->footerActions($this->getFormActions())
                    ->columns(2),
            ])
            ->statePath('data')
            ->model(Numerosis::model(TenantUser::class));
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->user()->update($data);

        Notification::make()
            ->title('Profile updated successfully')
            ->success()
            ->send();
    }

    public function resendVerificationEmail(): void
    {
        ResendVerificationNotification::run($this->user());

        Notification::make()
            ->title('Verification link sent')
            ->success()
            ->send();
    }
}
