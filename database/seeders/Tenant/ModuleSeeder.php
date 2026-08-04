<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Nwidart\Modules\Facades\Module;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        collect(Module::all())->values()->each(function (\Nwidart\Modules\Module $m) {
            \Nvade\Numerosis\Models\Tenant\Module::create([
                'name' => $m->getLowerName(),
                'description' => $m->getDescription(),
                'enabled' => false,
                'purchased_at' => null,
            ]);
        });
    }
}
