<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Numerosis
|--------------------------------------------------------------------------
|
| One config namespace, thirteen top-level keys, one file per key in
| `config/numerosis/`. Each partial returns its own `['key' => value]` pair
| and carries that key's documentation; this file only assembles them.
|
| **This file is never published.** Its `require __DIR__` paths would point
| at a host's own config directory the moment it were copied there, so
| `vendor:publish --tag=numerosis-config` writes the small override stub in
| `config/stubs/numerosis.php` instead. That is enough: HostConfig's
| deep-fill backfills every key a host's file omits, at every depth, so an
| override file only names what it actually changes. A host that published
| the old full-file copy keeps working unchanged — a complete file needs no
| backfill.
|
| Read the partials for the surface itself; `docs/host-requirements.md` §2
| lists every key a host may reasonably want to override.
|
*/

return array_merge(
    require __DIR__.'/numerosis/features.php',
    require __DIR__.'/numerosis/schedule.php',
    require __DIR__.'/numerosis/routes.php',
    require __DIR__.'/numerosis/domains.php',
    require __DIR__.'/numerosis/broadcasting.php',
    require __DIR__.'/numerosis/auth.php',
    require __DIR__.'/numerosis/social.php',
    require __DIR__.'/numerosis/views.php',
    require __DIR__.'/numerosis/cache.php',
    require __DIR__.'/numerosis/models.php',
    require __DIR__.'/numerosis/billing.php',
    require __DIR__.'/numerosis/tenancy.php',
);
