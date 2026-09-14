<?php

declare(strict_types=1);

use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Central\Tenant as TenantModel;
use Nvade\Numerosis\Tests\TestCase;
use Pest\Plugins\Parallel;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
 * A parallel worker reads the Playwright server's host and port out of a file
 * the parent process writes when it starts that server, and reading it before
 * the parent has written it fails every browser test in the run at once with
 * `file_get_contents(...playwright-server.json): Failed to open stream`.
 * Measured once in 30 consecutive runs (2026-09-14). Wait for the parent.
 */
if (Parallel::isWorker()) {
    $playwrightState = dirname(__DIR__).'/vendor/pestphp/pest-plugin-browser/.temp/playwright-server.json';
    $waitUntil = microtime(true) + 10;

    while (! file_exists($playwrightState) && microtime(true) < $waitUntil) {
        usleep(50_000);
    }
}

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
    $content = visit($url)->content();

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
