<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Invitations\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Events\Invitations\InvitationIssued;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Invitations\InvitationResource;
use Override;

class CreateInvitation extends CreateRecord
{
    protected static string $resource = InvitationResource::class;

    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $authenticated = Auth::user();

        throw_unless($authenticated, AuthenticationException::class);

        $userClass = Numerosis::model(User::class);

        /** @var User $invitedBy */
        $invitedBy = $userClass::where('global_id', $authenticated->global_id)->firstOrFail();

        $data['invited_by'] = $invitedBy->id;
        $data['tenant_id'] = tenant('id');

        return $data;
    }

    #[Override]
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Invitation $invitation */
        $invitation = static::getModel()::create($data);

        event(new InvitationIssued($invitation));

        return $invitation;
    }

    #[Override]
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Invitation sent')
            ->body('The invitation has been sent to '.($this->record instanceof Invitation ? $this->record->email : 'the invitee'));
    }

    #[Override]
    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
