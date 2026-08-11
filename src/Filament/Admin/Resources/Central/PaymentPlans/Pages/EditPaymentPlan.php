<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Override;

class EditPaymentPlan extends EditRecord
{
    protected static string $resource = PaymentPlanResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            // A plan with subscriptions pointing at it cannot be deleted, so
            // the action is disabled with a reason rather than left to fail
            // as a raw integrity-constraint error.
            DeleteAction::make()
                ->disabled(fn (PaymentPlan $record): bool => $record->subscriptions()->exists())
                ->tooltip(fn (PaymentPlan $record): ?string => $record->subscriptions()->exists()
                    ? 'Cannot delete — tenants are subscribed to this plan. Mark it unavailable instead.'
                    : null)
                ->modalDescription('This permanently removes the plan and its feature list. It only works while no subscription references it.'),
        ];
    }

    /**
     * Warns when a price was edited. The local price columns are display
     * only — subscribers are charged whatever the linked Stripe Price says,
     * and Stripe Prices are immutable. Changing the number here changes what
     * this panel shows, not what anyone pays.
     */
    protected function afterSave(): void
    {
        $record = $this->record;

        if (! $record instanceof PaymentPlan) {
            return;
        }

        $priceChanged = $record->wasChanged('monthly_price') || $record->wasChanged('yearly_price');

        if (! $priceChanged) {
            return;
        }

        Notification::make()
            ->title('Price updated locally only')
            ->body('Stripe Prices are immutable — this does not change what existing or new subscribers are charged. To actually change the charge, create a new Price in Stripe and update the Price ID on the Pricing & Stripe tab.')
            ->warning()
            ->persistent()
            ->send();
    }
}
