<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders\Central;

use Illuminate\Database\Seeder;
use Nvade\Numerosis\Models\Central\ModuleOffering;

class ModuleOfferingSeeder extends Seeder
{
    /**
     * Seeds from config('numerosis.modules.catalogue'), the way PaymentPlanSeeder
     * seeds from config('numerosis.billing.plans').
     */
    public function run(): void
    {
        foreach (config('numerosis.modules.catalogue', []) as $module) {
            ModuleOffering::updateOrCreate(
                ['slug' => $module['slug']],
                $module,
            );
        }
    }
}
