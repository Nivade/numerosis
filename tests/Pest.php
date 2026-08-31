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

/*
 * tests/Browser deliberately gets no directory-wide uses(): its files do not
 * share one base class. A path-mode test has to boot the application in path
 * mode, which is a decision taken before boot (routes, identification
 * middleware and the tenant panel's own registration all depend on it), so it
 * extends its own TestCase — and Pest refuses a per-file uses() for a folder
 * that already has one ("The folder [...] already uses the test case [...]").
 * Each browser test file declares its harness explicitly instead.
 *
 * What they do share: pestphp/pest-plugin-browser serves the application
 * **in-process** (an amphp socket in front of the same booted kernel the test
 * holds), so a request the browser makes sees RefreshDatabase's open
 * transaction and the tenant databases CloneTenantSchema just built. A
 * separate `artisan serve` process would see neither.
 */
