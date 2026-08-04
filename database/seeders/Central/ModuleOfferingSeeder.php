<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders\Central;

use Nvade\Numerosis\Models\Central\ModuleOffering;
use Illuminate\Database\Seeder;

class ModuleOfferingSeeder extends Seeder
{
    /**
     * Seeds from config('modules.catalogue'), the way PaymentPlanSeeder
     * seeds from config('numerosis-billing.plans').
     */
    public function run(): void
    {
        foreach (config('modules.catalogue', []) as $module) {
            ModuleOffering::updateOrCreate(
                ['slug' => $module['slug']],
                $module,
            );
        }
    }
}
