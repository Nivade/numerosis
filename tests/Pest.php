<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Tests\Support\CloneTenantSchema;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Migrating and seeding a tenant database costs ~1.9s, and QUEUE_CONNECTION=sync
 * makes every Tenant creation pay it inline. Copying a template built once per
 * process costs a fraction of that. This file is loaded before any test runs,
 * including the PHPUnit-style test classes that do not go through uses().
 */
TenancyServiceProvider::$tenantCreatedJobs = [
    CreateDatabase::class,
    CloneTenantSchema::class,
];

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
