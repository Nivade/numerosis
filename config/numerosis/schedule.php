<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduled command switches
    |--------------------------------------------------------------------------
    |
    | Booleans, not feature classes — a cron entry is an operations surface,
    | not an app feature. "Does this deployment's cron run this command" is an
    | operations question, not a product one. telescope:prune is gated
    | separately, by class_exists — not a key here, since it has exactly one
    | owner already.
    |
    | Read by NumerosisServiceProvider::registerSchedule(), which the package
    | registers itself. A host does not (and must not) load a routes file for
    | these: see that method's docblock for the consumer-invisible bug that
    | arrangement caused.
    |
    */

    'schedule' => [
        'prune_orphaned_customers' => (bool) env('SCHEDULE_PRUNE_ORPHANED_CUSTOMERS', true),
        'prune_stalled_provisions' => (bool) env('SCHEDULE_PRUNE_STALLED_PROVISIONS', true),
    ],

];
