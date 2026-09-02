<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Default is empty, which is the whole point: this package ships the
    | module *system*, never a module. A host with no modules needs to set
    | nothing, and package code reading this no longer depends on a
    | host-owned `config/modules.php` existing at all — it used to, with no
    | package default, so `Config::array('modules.plugins')` threw for any
    | consumer that had not hand-created that file.
    |
    | 'catalogue' seeds the central `modules` table (see
    | Database\Seeders\Central\ModuleOfferingSeeder). Prices are minor
    | currency units (cents); Stripe price ids belong in env so test and live
    | can differ without editing config. A recurring module must carry both
    | monthly_id and yearly_id — Stripe rejects a subscription whose items do
    | not share a billing interval, so ModuleForm requires both.
    |
    | 'plugins' (module slug => Filament plugin class) is declared here no
    | longer — core holds no reference to any concrete module, and no core
    | code ever reads that key. It defaults itself, in
    | Nvade\NumerosisFilament\NumerosisFilamentServiceProvider, the only
    | reader (NumerosisTenantPlugin registers each mapped plugin for tenants
    | that have the module enabled). Same pattern
    | numerosis.tenancy.registration.steps already uses for a key entirely
    | owned by nvade/numerosis-onboarding.
    |
    */

    'modules' => [
        'catalogue' => [],
    ],

];
