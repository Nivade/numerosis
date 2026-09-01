<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Numerosis — your overrides
|--------------------------------------------------------------------------
|
| Name only what you change. `HostConfig::numerosisConfig()` backfills every
| other key from the package's own defaults at every depth, so an absent key
| means "use the default", never "empty".
|
| Two caveats:
|
| - Lists (`features`, `permissions.contexts`, …) are backfilled only when
|   *entirely* absent. Naming one here replaces the package's list outright,
|   including with an empty array — a list's meaning is its contents and
|   order, not which keys are present.
| - The backfill cannot see a key you still name in an *outdated shape*: it
|   fills missing keys, not stale ones. That is what `schema_version` guards
|   — `numerosis:install` fails when the value below is behind the package's,
|   which is your cue to re-check this file against the current defaults.
|
| The defaults live in `vendor/nvade/numerosis/config/numerosis/`, one file
| per top-level key, each documenting its own surface.
| `docs/host-requirements.md` §2 lists what a host may reasonably override.
|
*/

return [

    'schema_version' => 1,

    // 'routes' => [
    //     // The view the always-registered `home` route renders. Point this
    //     // at your own page rather than declaring a second route on `/`:
    //     // core's is declared first and wins the path match.
    //     'home_view' => 'welcome',
    // ],

];
