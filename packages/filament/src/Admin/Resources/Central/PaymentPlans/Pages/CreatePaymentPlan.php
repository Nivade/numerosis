<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;

class CreatePaymentPlan extends CreateRecord
{
    protected static string $resource = PaymentPlanResource::class;
}
