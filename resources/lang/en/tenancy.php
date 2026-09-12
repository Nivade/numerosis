<?php

declare(strict_types=1);

return [

    'provisioning' => [

        /*
         * Keyed by the step's class basename, so a host's own step needs no
         * entry: it falls back to a headline of its class name.
         */
        'steps' => [
            'CreateTenant' => 'Creating your workspace',
            'CreateTenantDatabase' => 'Preparing the database',
            'MigrateTenantDatabase' => 'Building the database',
            'SeedTenantDatabase' => 'Adding roles and permissions',
            'AddTenantOwner' => 'Adding you as the owner',
            'LinkTenantSubscription' => 'Linking your subscription',
            'FinalizeTenantProvisioning' => 'Finishing up',
        ],

        'fallback' => 'Setting up…',
    ],

];
