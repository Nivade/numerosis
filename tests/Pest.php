<?php

declare(strict_types=1);

use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Central\Tenant as TenantModel;
use Nvade\Numerosis\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Sign a fresh tenant user in on the tenant guard, inside the tenant's own
 * database.
 *
 * `Auth::login()` rather than the test's own `actingAs()`: identical effect on
 * the guard, and it needs no reference to the TestCase — PHPStan types `$this`
 * inside a Pest closure as `Pest\PendingCalls\TestCall`, so passing it to a
 * typed parameter is an error it cannot see through.
 */
function signInTenantUser(TenantModel $tenant): void
{
    $tenant->run(function (): void {
        Auth::guard(Config::string('numerosis.auth.guards.tenant'))->login(TenantUser::factory()->create());
    });
}

/**
 * The tenant landing page rendered, rather than the "Server Error" body a
 * rendered 500 produces — which is all a failing tenant page says otherwise.
 */
function expectTenantLandingPage(string $url): void
{
    $content = (string) visit($url)->content();

    expect($content)->toContain(Config::string('app.name'));
    expect($content)->not->toContain('Server Error');
}

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
