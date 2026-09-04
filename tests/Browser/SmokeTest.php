<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;
use Pest\Browser\Playwright\Playwright;

uses(TestCase::class, RefreshDatabase::class);

/**
 * `Playwright::setHost()` is how a request reaches a specific virtual host.
 * The plugin's server always binds 127.0.0.1 and rewrites every visited URL to
 * it — `LaravelHttpServer::rewrite()` discards the host of an absolute URL —
 * but it also rewrites the inbound `Host` header to whatever this is set to,
 * per request. That is the only lever for a multi-domain application, and it
 * is global static state, so it is set explicitly in every test rather than
 * inherited from a previous one.
 */
/**
 * The login screen is Fortify's route rendering `numerosis::auth.login`, a
 * plain Blade form since Phase 4 — no Livewire on a guest auth screen. Over
 * real HTTP is the only place the `@csrf` that replaced Livewire's own token
 * is observable.
 */
it('serves the central login page over real HTTP', function (): void {
    Playwright::setHost('central.numerosistest.test');

    $page = visit('/login');

    $page->assertSee('Log in');
    $page->assertPresent('input[name="_token"]');
});
