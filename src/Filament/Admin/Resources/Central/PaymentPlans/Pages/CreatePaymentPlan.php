<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;

class CreatePaymentPlan extends CreateRecord
{
    protected static string $resource = PaymentPlanResource::class;
}
