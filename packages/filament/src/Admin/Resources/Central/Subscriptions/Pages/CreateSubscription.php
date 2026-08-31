<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\SubscriptionResource;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;
}
