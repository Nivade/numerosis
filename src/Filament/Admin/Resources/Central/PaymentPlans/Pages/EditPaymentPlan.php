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
            // subscriptions.payment_plan_id has no ON DELETE clause, so MySQL
            // defaults to RESTRICT: deleting a plan with any subscription
            // still pointing at it — active or not — previously surfaced as
            // a raw SQLSTATE 23000 integrity-constraint error, not a message
            // an admin could act on. Disabling the action up front and
            // saying why is the same shape as TenantResource's
            // suspend/restore guards.
            DeleteAction::make()
                ->disabled(fn (PaymentPlan $record): bool => $record->subscriptions()->exists())
                ->tooltip(fn (PaymentPlan $record): ?string => $record->subscriptions()->exists()
                    ? 'Cannot delete — tenants are subscribed to this plan. Mark it unavailable instead.'
                    : null)
                ->modalDescription('This permanently removes the plan and its feature list. It only works while no subscription references it.'),
        ];
    }

    /**
     * A plan's `monthly_price`/`yearly_price` are local display columns;
     * what a subscriber is actually charged is driven entirely by the
     * Stripe Price behind `monthly_id`/`yearly_id`. Stripe Prices are
     * immutable by design — editing the number here changes what this admin
     * panel *shows*, not what Stripe *charges*, and that gap is exactly the
     * kind of thing that looks like a bug three weeks later when someone
     * notices a subscriber's invoice doesn't match the plan page. Surface it
     * at the moment it can happen, not after.
     *
     * Not annotated #[Override]: `EditRecord::save()` calls this dynamically
     * via `callHook('afterSave')`, not through a declared parent method —
     * PHPStan correctly reports nothing here to override.
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
