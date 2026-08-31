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
it('serves the central login page over real HTTP', function (): void {
    Playwright::setHost('central.numerosistest.test');

    visit('/login')->assertSee('Log in');
});
