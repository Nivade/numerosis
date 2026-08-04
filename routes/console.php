<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

if (class_exists(Laravel\Telescope\Telescope::class)) {
    Schedule::command('telescope:prune')->daily();
}

if (Config::boolean('numerosis.schedule.prune_orphaned_customers')) {
    Schedule::command('billing:prune-orphaned-customers')->daily();
}

if (Config::boolean('numerosis.schedule.prune_stalled_provisions')) {
    Schedule::command('tenancy:prune-stalled-provisions')->hourly();
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
