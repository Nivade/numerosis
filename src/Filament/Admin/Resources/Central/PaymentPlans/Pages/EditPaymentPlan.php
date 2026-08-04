<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentPlan extends EditRecord
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
