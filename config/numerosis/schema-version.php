<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Schema version
    |--------------------------------------------------------------------------
    |
    | `HostConfig::numerosisConfig()` deep-fills any *missing* key in a
    | host's published file, at every depth — but a key the host's file
    | still names, just with an older shape (a restructured array, a
    | renamed top-level key its file still carries the old name of), is
    | invisible to that fill: the key isn't missing, so nothing touches it.
    | `InstallNumerosisCommand::verifyConfigSchemaVersion()` reads this
    | value straight out of a *published* config/numerosis.php (not through
    | config(), which would already show the package's current default —
    | see that method's own docblock) and fails loudly when it's behind.
    |
    | Bump this in the same commit as any change to a top-level key's name
    | or shape — not for additions inside an existing keyed array, which the
    | deep-fill already covers safely.
    |
    */

    'schema_version' => 2,

];
