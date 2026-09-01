<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Both keys default to empty, which is the whole point: this package ships
    | the module *system*, never a module. A host with no modules needs to set
    | nothing, and package code reading these no longer depends on a
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
    | 'plugins' maps a module slug to its Filament plugin class, which
    | NumerosisTenantPlugin registers only for tenants that have the module
    | enabled. Core holds no reference to any concrete module.
    |
    */

    'modules' => [
        'catalogue' => [],

        'plugins' => [],
    ],

];
