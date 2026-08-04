<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Modules;

use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Events\Modules\ModulePurchased;

class QueueModuleMigration
{
    public function handle(ModulePurchased $event): void
    {
        MigrateModules::dispatch($event->tenant, $event->slug);
    }
}
