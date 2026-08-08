<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\SubscriptionResource;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;
}
